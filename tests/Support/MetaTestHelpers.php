<?php

namespace Tests\Support;

use App\Enums\MetaConnectionStatus;
use App\Models\MetaConnection;
use App\Models\MetaFormMapping;
use App\Models\MetaLeadEvent;
use App\Models\MetaPage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Facades\Tenancy;
use Illuminate\Support\Facades\Http;

/**
 * Helpers for Meta tests. Everything here is MOCKED: no test ever contacts Meta.
 * Fixtures under tests/Fixtures/meta are hand-written approximations of Meta's documented payloads, not captures.
 */
trait MetaTestHelpers
{
    protected string $metaSecret = 'test-app-secret-not-real';

    protected function configureMeta(): void
    {
        config([
            'meta.app_id' => '123456789',
            'meta.app_secret' => $this->metaSecret,
            'meta.webhook_verify_token' => 'test-verify-token',
            'meta.graph_version' => 'v99.0',
            'meta.graph_base_url' => 'https://graph.example.test',
            'meta.oauth_dialog_base_url' => 'https://www.example.test',
            'meta.oauth_redirect_uri' => 'https://crm.example.test/integrations/meta/callback',
        ]);
    }

    protected function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/meta/{$name}.json")), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array{0: MetaConnection, 1: MetaPage, 2: MetaFormMapping} */
    protected function connectMeta(Tenant $tenant, ?User $creator = null, string $pageId = '100000000000001', string $formId = '700000000000001', bool $enabled = true, ?int $ownerId = null): array
    {
        return Tenancy::runAs($tenant, function () use ($creator, $pageId, $formId, $enabled, $ownerId, $tenant) {
            $connection = MetaConnection::query()->create([
                'created_by' => $creator?->getKey(),
                'meta_user_id' => 'u'.$pageId,
                'meta_user_name' => 'Page Admin',
                'status' => MetaConnectionStatus::Active,
                'granted_permissions' => ['leads_retrieval', 'pages_show_list'],
            ]);
            $page = MetaPage::query()->create([
                'meta_connection_id' => $connection->getKey(),
                'page_id' => $pageId,
                'name' => 'Test Page',
                'access_token' => 'PAGE-TOKEN-'.$pageId,
                'subscription_status' => 'subscribed',
            ]);
            $mapping = MetaFormMapping::query()->create([
                'meta_page_id' => $page->getKey(),
                'form_id' => $formId,
                'form_name' => 'Test form',
                'lead_source_id' => $this->sourceId($tenant, 'facebook_ads'),
                'pipeline_stage_id' => $this->stageId($tenant, 'new'),
                'default_owner_id' => $ownerId,
                'is_enabled' => $enabled,
            ]);

            return [$connection, $page, $mapping];
        });
    }

    protected function signature(string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $this->metaSecret);
    }

    /** POST a correctly signed payload to the webhook. */
    protected function postWebhook(array|string $payload, ?string $signature = null, array $headers = [])
    {
        $body = is_array($payload) ? json_encode($payload) : $payload;

        return $this->call('POST', '/webhooks/meta', [], [], [], $this->transformHeadersToServerVars($headers + [
            'X-Hub-Signature-256' => $signature ?? $this->signature($body),
            'Content-Type' => 'application/json',
        ]), $body);
    }

    protected function fakeGraphLead(?array $lead = null, int $status = 200): void
    {
        $lead ??= $this->fixture('lead_full');
        Http::fake(['graph.example.test/*' => Http::response($lead, $status)]);
    }

    protected function eventFor(Tenant $tenant, string $leadgenId = '900000000000001'): ?MetaLeadEvent
    {
        return Tenancy::withoutScope(fn () => MetaLeadEvent::query()->where('leadgen_id', $leadgenId)->first());
    }
}
