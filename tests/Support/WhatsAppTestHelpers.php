<?php

namespace Tests\Support;

use App\Enums\WhatsAppAccountStatus;
use App\Models\Tenant;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Support\Facades\Tenancy;

/**
 * Helpers for WhatsApp tests. Everything is MOCKED: no test contacts Meta. Payloads follow Meta's documented
 * Cloud API webhook shape as understood at authoring time; they are hand-written, not captured.
 */
trait WhatsAppTestHelpers
{
    protected string $waSecret = 'test-app-secret-not-real';

    protected function configureWhatsApp(): void
    {
        config([
            'meta.app_id' => '123456789',
            'meta.app_secret' => $this->waSecret,
            'meta.graph_version' => 'v99.0',
            'meta.graph_base_url' => 'https://graph.example.test',
            'whatsapp.webhook_verify_token' => 'wa-verify-token',
        ]);
    }

    protected function connectWhatsApp(Tenant $tenant, string $phoneNumberId = '110000000000001', array $attributes = []): WhatsAppAccount
    {
        return Tenancy::runAs($tenant, fn () => WhatsAppAccount::query()->create($attributes + [
            'waba_id' => '220000000000001',
            'phone_number_id' => $phoneNumberId,
            'display_phone_number' => '+91 22 4000 0000',
            'verified_name' => 'Test Business',
            'access_token' => 'WA-TOKEN-'.$phoneNumberId,
            'status' => WhatsAppAccountStatus::Active,
            'subscription_status' => 'subscribed',
        ]));
    }

    /** A conversation with an inbound message $minutesAgo minutes ago (so the 24h window state is controllable). */
    protected function conversation(Tenant $tenant, WhatsAppAccount $account, string $waId = '919876543210', ?int $leadId = null, int $minutesAgo = 5): WhatsAppConversation
    {
        return Tenancy::runAs($tenant, function () use ($account, $waId, $leadId, $minutesAgo) {
            $contact = WhatsAppContact::query()->create(['wa_id' => $waId, 'phone_e164' => '+'.$waId, 'profile_name' => 'Ravi', 'lead_id' => $leadId]);
            $conversation = WhatsAppConversation::query()->create([
                'whatsapp_account_id' => $account->getKey(),
                'whatsapp_contact_id' => $contact->getKey(),
                'lead_id' => $leadId,
                'link_status' => $leadId ? WhatsAppConversation::LINKED : WhatsAppConversation::UNLINKED,
                'last_inbound_at' => now()->subMinutes($minutesAgo),
                'last_message_at' => now()->subMinutes($minutesAgo),
            ]);
            WhatsAppMessage::query()->create([
                'whatsapp_conversation_id' => $conversation->getKey(),
                'direction' => 'in', 'wamid' => 'wamid.seed'.$waId, 'kind' => 'text', 'body' => 'Hello there',
                'status' => 'received', 'provider_timestamp' => now()->subMinutes($minutesAgo),
            ]);

            return $conversation;
        });
    }

    protected function template(Tenant $tenant, WhatsAppAccount $account, string $name = 'follow_up', string $status = 'APPROVED', string $body = 'Hi {{1}}, thanks for your interest in {{2}}.'): WhatsAppTemplate
    {
        return Tenancy::runAs($tenant, fn () => WhatsAppTemplate::query()->create([
            'whatsapp_account_id' => $account->getKey(),
            'name' => $name, 'language' => 'en', 'category' => 'MARKETING', 'status' => $status,
            'components' => [['type' => 'BODY', 'text' => $body]],
            'body_param_count' => preg_match_all('/\{\{\d+\}\}/', $body),
            'synced_at' => now(),
        ]));
    }

    /** @param  list<array<string, mixed>>  $messages @param  list<array<string, mixed>>  $statuses */
    protected function waPayload(array $messages = [], array $statuses = [], string $phoneNumberId = '110000000000001', array $contacts = []): array
    {
        $value = ['messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '912240000000', 'phone_number_id' => $phoneNumberId]];
        if ($messages !== []) {
            $value['contacts'] = $contacts ?: [['profile' => ['name' => 'Ravi Kumar'], 'wa_id' => $messages[0]['from'] ?? '919876543210']];
            $value['messages'] = $messages;
        }
        if ($statuses !== []) {
            $value['statuses'] = $statuses;
        }

        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '220000000000001', 'changes' => [['field' => 'messages', 'value' => $value]]]]];
    }

    protected function textMessage(string $wamid = 'wamid.HBgMOTE5ODc2NTQzMjEwFQIAEhggAAA=', string $from = '919876543210', string $body = 'Hi, I saw your ad', ?int $timestamp = null): array
    {
        return ['from' => $from, 'id' => $wamid, 'timestamp' => (string) ($timestamp ?? now()->timestamp), 'type' => 'text', 'text' => ['body' => $body]];
    }

    protected function postWhatsApp(array|string $payload, ?string $signature = null)
    {
        $body = is_array($payload) ? json_encode($payload) : $payload;

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], $this->transformHeadersToServerVars([
            'X-Hub-Signature-256' => $signature ?? 'sha256='.hash_hmac('sha256', $body, $this->waSecret),
            'Content-Type' => 'application/json',
        ]), $body);
    }
}
