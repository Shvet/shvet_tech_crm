<?php

namespace App\Actions\WhatsApp;

use App\Enums\TenantStatus;
use App\Enums\WhatsAppAccountStatus;
use App\Enums\WhatsAppMessageStatus;
use App\Models\Tenant;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppLog;
use App\Support\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a signature-verified WhatsApp webhook payload: stores inbound messages and moves outbound statuses forward.
 *
 * - The tenant comes ONLY from our own whatsapp_accounts row for metadata.phone_number_id (globally unique). Unknown or
 *   disconnected numbers are ignored; nothing in the payload can name a tenant.
 * - Everything is written before the controller answers 200, so a database failure makes Meta redeliver.
 * - Idempotent: inbound messages collapse on their wamid; statuses only ever move forward (sent < delivered < read,
 *   failed is terminal), so redelivered or out-of-order statuses are harmless.
 * - No Graph call happens here (text arrives in the webhook), so no queue is needed for inbound.
 */
class ReceiveWhatsAppWebhook
{
    public function __construct(private LinkConversationToLead $link) {}

    /** @param  array<string, mixed>  $payload @return array{messages: int, duplicates: int, statuses: int, ignored: int} */
    public function __invoke(array $payload): array
    {
        $stats = ['messages' => 0, 'duplicates' => 0, 'statuses' => 0, 'ignored' => 0];
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return $stats;
        }

        $budget = (int) config('whatsapp.webhook_max_items', 200);

        foreach ($this->list($payload['entry'] ?? null) as $entry) {
            foreach ($this->list($entry['changes'] ?? null) as $change) {
                $value = $change['value'] ?? null;
                if (($change['field'] ?? null) !== 'messages' || ! is_array($value)) {
                    continue;
                }

                $phoneNumberId = $this->id($value['metadata']['phone_number_id'] ?? null);
                $account = $phoneNumberId === null ? null : $this->account($phoneNumberId);
                if ($account === null) {
                    $stats['ignored']++;
                    WhatsAppLog::info('webhook.unknown_number');

                    continue;
                }

                $tenant = Tenancy::withoutScope(fn () => Tenant::query()->find($account->tenant_id));
                if (! $tenant || $tenant->status !== TenantStatus::Active) {
                    $stats['ignored']++;

                    continue;
                }

                Tenancy::runAs($tenant, function () use ($account, $value, &$stats, &$budget) {
                    $names = $this->profileNames($value['contacts'] ?? null);

                    foreach ($this->list($value['messages'] ?? null) as $message) {
                        if ($budget-- <= 0) {
                            return;
                        }
                        $result = $this->inbound($account, $message, $names);
                        if ($result !== null) {
                            $stats[$result ? 'messages' : 'duplicates']++;
                        }
                    }

                    foreach ($this->list($value['statuses'] ?? null) as $status) {
                        if ($budget-- <= 0) {
                            return;
                        }
                        if ($this->status($status)) {
                            $stats['statuses']++;
                        }
                    }

                    WhatsAppAccount::query()->whereKey($account->getKey())->update(['last_webhook_at' => now()]);
                });
            }
        }

        return $stats;
    }

    private function account(string $phoneNumberId): ?WhatsAppAccount
    {
        return Tenancy::withoutScope(fn () => WhatsAppAccount::query()
            ->where('phone_number_id', $phoneNumberId)
            ->where('status', '!=', WhatsAppAccountStatus::Disconnected->value)
            ->first());
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, string>  $names
     * @return bool|null true = stored, false = duplicate, null = malformed/skipped
     */
    private function inbound(WhatsAppAccount $account, array $message, array $names): ?bool
    {
        $waId = $this->waId($message['from'] ?? null);
        $wamid = $this->wamid($message['id'] ?? null);
        if ($waId === null || $wamid === null) {
            return null;
        }

        if (WhatsAppMessage::query()->where('wamid', $wamid)->exists()) {
            return false;
        }

        $at = isset($message['timestamp']) && is_numeric($message['timestamp'])
            ? Carbon::createFromTimestampUTC((int) $message['timestamp']) : now();
        if ($at->isFuture()) {
            $at = now();
        }

        [$kind, $body] = $this->content($message);

        $contact = $this->contact($waId, $names[$waId] ?? null);
        $conversation = $this->conversation($account, $contact);

        try {
            DB::transaction(function () use ($conversation, $wamid, $kind, $body, $at) {
                WhatsAppMessage::query()->create([
                    'whatsapp_conversation_id' => $conversation->getKey(),
                    'direction' => WhatsAppMessage::IN,
                    'wamid' => $wamid,
                    'kind' => $kind,
                    'body' => $body,
                    'status' => WhatsAppMessageStatus::Received,
                    'provider_timestamp' => $at,
                ]);

                $locked = WhatsAppConversation::query()->lockForUpdate()->findOrFail($conversation->getKey());
                $locked->unread_count++;
                if ($locked->last_inbound_at === null || $at->greaterThan($locked->last_inbound_at)) {
                    $locked->last_inbound_at = $at;
                }
                if ($locked->last_message_at === null || $at->greaterThan($locked->last_message_at)) {
                    $locked->last_message_at = $at;
                }
                $locked->save();
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false; // concurrent redelivery won the race
            }
            throw $e;
        }

        $this->consentKeyword($contact, $kind, $body);

        if ($conversation->link_status === WhatsAppConversation::UNLINKED) {
            ($this->link)->automatic($conversation->fresh(), $account);
        }

        return true;
    }

    /** @param  array<string, mixed>  $status */
    private function status(array $status): bool
    {
        $wamid = $this->wamid($status['id'] ?? null);
        $new = WhatsAppMessageStatus::tryFrom((string) ($status['status'] ?? ''));
        if ($wamid === null || $new === null || $new === WhatsAppMessageStatus::Received || $new === WhatsAppMessageStatus::Queued) {
            return false;
        }

        return DB::transaction(function () use ($wamid, $new, $status) {
            $message = WhatsAppMessage::query()->where('wamid', $wamid)->where('direction', WhatsAppMessage::OUT)->lockForUpdate()->first();
            if ($message === null) {
                // Status for a message we do not (yet) know. Later statuses will still apply. See docs §limitations.
                WhatsAppLog::info('webhook.status_unknown_message');

                return false;
            }

            $current = $message->status;
            if ($current === WhatsAppMessageStatus::Failed || $new->rank() <= $current->rank()) {
                return false; // never move backwards; failed is terminal
            }

            $message->status = $new;
            if ($new === WhatsAppMessageStatus::Failed) {
                $error = is_array($status['errors'][0] ?? null) ? $status['errors'][0] : [];
                $message->error_code = isset($error['code']) && is_numeric($error['code']) ? 'wa_'.(int) $error['code'] : 'wa_failed';
                $message->error_title = isset($error['title']) ? mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $error['title']) ?? '', 0, 255) : null;
            }
            $message->save();

            return true;
        });
    }

    private function contact(string $waId, ?string $profileName): WhatsAppContact
    {
        $profileName = $profileName === null ? null : mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $profileName) ?? ''), 0, 120);

        $contact = WhatsAppContact::query()->where('wa_id', $waId)->first();
        if ($contact === null) {
            try {
                $contact = WhatsAppContact::query()->create([
                    'wa_id' => $waId,
                    'phone_e164' => '+'.$waId,
                    'profile_name' => $profileName ?: null,
                ]);
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) !== 1062) {
                    throw $e;
                }
                $contact = WhatsAppContact::query()->where('wa_id', $waId)->firstOrFail();
            }
        } elseif ($profileName && $contact->profile_name !== $profileName) {
            $contact->update(['profile_name' => $profileName]);
        }

        return $contact;
    }

    private function conversation(WhatsAppAccount $account, WhatsAppContact $contact): WhatsAppConversation
    {
        $find = fn () => WhatsAppConversation::query()
            ->where('whatsapp_account_id', $account->getKey())
            ->where('whatsapp_contact_id', $contact->getKey())
            ->first();

        if ($existing = $find()) {
            return $existing;
        }

        try {
            return WhatsAppConversation::query()->create([
                'whatsapp_account_id' => $account->getKey(),
                'whatsapp_contact_id' => $contact->getKey(),
                'lead_id' => $contact->lead_id,
                'link_status' => $contact->lead_id ? WhatsAppConversation::LINKED : WhatsAppConversation::UNLINKED,
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }

            return $find() ?? throw $e;
        }
    }

    /** @param  array<string, mixed>  $message @return array{0: string, 1: ?string} */
    private function content(array $message): array
    {
        $type = (string) ($message['type'] ?? 'unsupported');
        $max = (int) config('whatsapp.max_text_length', 4096);
        $clean = fn ($text) => is_string($text) && $text !== '' ? mb_substr($text, 0, $max) : null;

        return match ($type) {
            'text' => ['text', $clean($message['text']['body'] ?? null)],
            'button' => ['button', $clean($message['button']['text'] ?? null)],
            'interactive' => ['interactive', $clean($message['interactive']['button_reply']['title'] ?? $message['interactive']['list_reply']['title'] ?? null)],
            'image', 'video', 'document' => [$type, $clean($message[$type]['caption'] ?? null)],
            'audio', 'sticker', 'location', 'contacts' => [$type, null],
            'reaction' => ['reaction', $clean($message['reaction']['emoji'] ?? null)],
            default => ['unsupported', null],
        };
    }

    private function consentKeyword(WhatsAppContact $contact, string $kind, ?string $body): void
    {
        if ($kind !== 'text' || $body === null) {
            return;
        }
        $word = mb_strtolower(trim($body));

        if (in_array($word, (array) config('whatsapp.opt_out_keywords', []), true) && ! $contact->isOptedOut()) {
            $contact->update(['opted_out_at' => now()]);
            WhatsAppLog::info('contact.opted_out', ['contact' => $contact->getKey()]);
        } elseif (in_array($word, (array) config('whatsapp.opt_in_keywords', []), true) && $contact->isOptedOut()) {
            $contact->update(['opted_out_at' => null]);
            WhatsAppLog::info('contact.opted_in', ['contact' => $contact->getKey()]);
        }
    }

    /** @return array<string, string> wa_id => profile name */
    private function profileNames(mixed $contacts): array
    {
        $out = [];
        foreach ($this->list($contacts) as $c) {
            $waId = $this->waId($c['wa_id'] ?? null);
            $name = $c['profile']['name'] ?? null;
            if ($waId !== null && is_string($name) && $name !== '') {
                $out[$waId] = $name;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private function id(mixed $value): ?string
    {
        return is_scalar($value) && preg_match('/^\d{1,40}$/', (string) $value) === 1 ? (string) $value : null;
    }

    /** A WhatsApp id is the contact's phone number in international format without "+": digits only. */
    private function waId(mixed $value): ?string
    {
        return is_scalar($value) && preg_match('/^\d{6,20}$/', (string) $value) === 1 ? (string) $value : null;
    }

    private function wamid(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_.=:\-+\/]{8,191}$/', $value) === 1 ? $value : null;
    }
}
