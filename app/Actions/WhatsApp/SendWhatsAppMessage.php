<?php

namespace App\Actions\WhatsApp;

use App\Enums\WhatsAppMessageStatus;
use App\Jobs\WhatsApp\DeliverWhatsAppMessage;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\WhatsAppLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Queues an outbound WhatsApp message after enforcing Meta's messaging rules server-side:
 *   - the workspace number must be connected and active;
 *   - a contact who opted out (STOP) gets nothing;
 *   - free-form text only inside the 24-hour customer-service window; outside it, only an APPROVED template;
 *   - a template must receive exactly as many body parameters as it has placeholders.
 * The message is stored as "queued" first, then a job delivers it, so the UI never waits on Meta.
 */
class SendWhatsAppMessage
{
    /** @param  array{type: string, body?: ?string, template_id?: ?int, params?: list<string>}  $data */
    public function __invoke(User $actor, WhatsAppConversation $conversation, array $data): WhatsAppMessage
    {
        $account = $conversation->account;
        if (! $account || ! $account->isActive()) {
            throw ValidationException::withMessages(['body' => 'WhatsApp is not connected for this workspace (or needs to be reconnected by an admin).']);
        }
        if ($conversation->contact->isOptedOut()) {
            throw ValidationException::withMessages(['body' => 'This contact opted out of WhatsApp messages (sent STOP). Nothing can be sent until they write START.']);
        }

        $params = [];
        if ($data['type'] === 'text') {
            if (! $conversation->serviceWindowOpen()) {
                throw ValidationException::withMessages(['body' => 'The 24-hour reply window is closed. Send an approved template instead.']);
            }
            $attributes = ['kind' => 'text', 'body' => (string) $data['body']];
        } else {
            $template = WhatsAppTemplate::query()->where('whatsapp_account_id', $account->getKey())->find($data['template_id'] ?? 0);
            if (! $template || ! $template->isApproved()) {
                throw ValidationException::withMessages(['template_id' => 'Choose an approved template.']);
            }
            $params = array_values($data['params'] ?? []);
            if (count($params) !== $template->body_param_count) {
                throw ValidationException::withMessages(['params' => "This template needs exactly {$template->body_param_count} value(s)."]);
            }
            $attributes = [
                'kind' => 'template',
                'body' => self::render($template->bodyText() ?? $template->name, $params),
                'template_name' => $template->name,
                'template_language' => $template->language,
            ];
        }

        $message = DB::transaction(function () use ($actor, $conversation, $attributes) {
            $message = WhatsAppMessage::query()->create($attributes + [
                'whatsapp_conversation_id' => $conversation->getKey(),
                'direction' => WhatsAppMessage::OUT,
                'status' => WhatsAppMessageStatus::Queued,
                'sent_by' => $actor->getKey(),
                'provider_timestamp' => now(),
            ]);
            $conversation->update(['last_message_at' => now(), 'unread_count' => 0]);

            return $message;
        });

        try {
            DeliverWhatsAppMessage::dispatch($message->public_id, $params, (int) $message->tenant_id);
        } catch (Throwable $e) {
            $message->update(['status' => WhatsAppMessageStatus::Failed, 'error_code' => 'dispatch_failed', 'error_title' => 'Could not queue the message. Try again.']);
            WhatsAppLog::error('send.dispatch_failed', ['message' => $message->public_id, 'exception' => $e::class]);
        }

        return $message;
    }

    /** @param  list<string>  $params */
    public static function render(string $text, array $params): string
    {
        return (string) preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', fn ($m) => $params[(int) $m[1] - 1] ?? $m[0], $text);
    }
}
