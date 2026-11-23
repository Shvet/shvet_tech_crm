<?php

namespace App\Jobs\WhatsApp;

use App\Actions\WhatsApp\ValidateWhatsAppAccount;
use App\Enums\WhatsAppMessageStatus;
use App\Exceptions\MetaApiException;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\CloudApiClient;
use App\Services\WhatsApp\WhatsAppLog;
use App\Support\Facades\Tenancy;
use App\Tenancy\Queue\QueuesForTenant;
use App\Tenancy\Queue\TenantAware;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one queued outbound message. Carries only the tenant id, the message's public id and template parameters
 * (the payload is encrypted on the queue because parameters can hold personal data).
 *
 * - Runs only while the message is still "queued" (a redelivered job after success is a no-op).
 * - Rules are re-checked at send time (account active, contact not opted out) because things change while queued.
 * - Retryable Meta errors (rate limits, outages) back off; anything else fails the message with a readable reason.
 *
 * Known limit: if the worker dies after Meta accepted the message but before the wamid is saved, a retry can send it
 * twice. The Cloud API has no idempotency key; see docs/PHASE_4_WHATSAPP.md.
 */
class DeliverWhatsAppMessage implements ShouldBeEncrypted, ShouldQueue, TenantAware
{
    use Queueable, QueuesForTenant;

    public int $tries;

    public int $timeout = 60;

    /** @param  list<string>  $params */
    public function __construct(public string $messagePublicId, public array $params = [], ?int $tenantId = null)
    {
        $this->captureTenant($tenantId);
        $this->tries = (int) config('whatsapp.job.tries', 5);
    }

    public function handle(CloudApiClient $client): void
    {
        $message = WhatsAppMessage::query()->with('conversation.account', 'conversation.contact')->where('public_id', $this->messagePublicId)->first();
        if (! $message || $message->status !== WhatsAppMessageStatus::Queued || $message->direction !== WhatsAppMessage::OUT) {
            return;
        }

        $conversation = $message->conversation;
        $account = $conversation->account;
        $contact = $conversation->contact;

        if (! $account->isActive()) {
            $this->markFailed($message, 'account_inactive', 'WhatsApp is not connected (or needs reconnecting). Not sent.');

            return;
        }
        if ($contact->isOptedOut()) {
            $this->markFailed($message, 'opted_out', 'The contact opted out before this was sent. Not sent.');

            return;
        }
        if ($message->kind === 'text' && ! $conversation->serviceWindowOpen()) {
            $this->markFailed($message, 'window_closed', 'The 24-hour reply window closed before this was sent. Use a template.');

            return;
        }

        try {
            $wamid = $message->kind === 'template'
                ? $client->sendTemplate($account->phone_number_id, (string) $account->access_token, $contact->wa_id, (string) $message->template_name, (string) $message->template_language, $this->params)
                : $client->sendText($account->phone_number_id, (string) $account->access_token, $contact->wa_id, (string) $message->body);
        } catch (MetaApiException $e) {
            $this->handleError($message, $e);

            return;
        }

        WhatsAppMessage::query()->whereKey($message->getKey())->where('status', WhatsAppMessageStatus::Queued->value)
            ->update(['wamid' => $wamid, 'status' => WhatsAppMessageStatus::Sent->value, 'error_code' => null, 'error_title' => null]);
        WhatsAppLog::info('send.accepted', ['message' => $message->public_id]);
    }

    private function handleError(WhatsAppMessage $message, MetaApiException $e): void
    {
        if ($e->requiresReauthorization()) {
            ValidateWhatsAppAccount::recordFailure($message->conversation->account, $e);
        }

        if ($e->isRetryable() && $this->attempts() < $this->tries) {
            $schedule = (array) config('whatsapp.job.backoff', [10, 60, 300, 900]);
            $delay = max((int) ($schedule[min($this->attempts() - 1, count($schedule) - 1)] ?? 60), (int) $e->retryAfterSeconds);
            WhatsAppLog::info('send.retry', ['message' => $message->public_id, 'attempt' => $this->attempts(), 'delay' => $delay, 'code' => $e->code()]);
            $this->release(min($delay, 3600));

            return;
        }

        $this->markFailed($message, $e->code(), self::reason($e));
    }

    public static function reason(MetaApiException $e): string
    {
        return match (true) {
            $e->metaCode === 131047 => 'More than 24 hours since the contact last wrote. Use an approved template.',
            $e->metaCode === 131026 => 'Meta could not deliver to this number (it may not use WhatsApp).',
            $e->metaCode !== null && $e->metaCode >= 132000 && $e->metaCode <= 132999 => 'Meta rejected the template (not approved, wrong language or wrong parameters).',
            $e->category === MetaApiException::AUTH => 'Meta rejected the saved access token. An admin must reconnect WhatsApp.',
            $e->category === MetaApiException::PERMISSION => 'The token is not allowed to send from this number.',
            $e->category === MetaApiException::RATE_LIMIT => 'Meta is rate limiting this number. Try again later.',
            default => 'Meta did not accept the message ('.$e->code().').',
        };
    }

    private function markFailed(WhatsAppMessage $message, string $code, string $title): void
    {
        WhatsAppMessage::query()->whereKey($message->getKey())->where('status', WhatsAppMessageStatus::Queued->value)
            ->update(['status' => WhatsAppMessageStatus::Failed->value, 'error_code' => mb_substr($code, 0, 64), 'error_title' => mb_substr($title, 0, 255)]);
        WhatsAppLog::warning('send.failed', ['message' => $message->public_id, 'code' => $code]);
    }

    /** Retries exhausted or crash: never leave the message spinning as "queued". */
    public function failed(Throwable $e): void
    {
        $tenant = Tenancy::withoutScope(fn () => Tenant::query()->find($this->tenantId()));
        if (! $tenant) {
            return;
        }

        Tenancy::runAs($tenant, function () use ($e) {
            [$code, $title] = $e instanceof MetaApiException ? [$e->code(), self::reason($e)] : ['job_failed', 'Sending failed unexpectedly. Try again.'];
            WhatsAppMessage::query()->where('public_id', $this->messagePublicId)->where('status', WhatsAppMessageStatus::Queued->value)
                ->update(['status' => WhatsAppMessageStatus::Failed->value, 'error_code' => mb_substr($code, 0, 64), 'error_title' => $title]);
        });
    }
}
