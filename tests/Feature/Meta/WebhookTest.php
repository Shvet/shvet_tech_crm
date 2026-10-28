<?php

namespace Tests\Feature\Meta;

use App\Enums\MetaEventStatus;
use App\Jobs\Meta\ProcessMetaLeadEvent;
use App\Models\MetaLeadEvent;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MetaTestHelpers;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use MetaTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
    }

    // ---- handshake --------------------------------------------------------------------------------------------

    public function test_handshake_echoes_challenge_with_correct_token(): void
    {
        $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=test-verify-token&hub.challenge=1158201444')
            ->assertOk()->assertSee('1158201444', false)->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function test_handshake_rejects_wrong_token_wrong_mode_and_missing_params(): void
    {
        $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=123')->assertForbidden();
        $this->get('/webhooks/meta?hub.mode=unsubscribe&hub.verify_token=test-verify-token&hub.challenge=123')->assertForbidden();
        $this->get('/webhooks/meta')->assertForbidden();
    }

    public function test_handshake_refuses_everything_when_no_verify_token_is_configured(): void
    {
        config(['meta.webhook_verify_token' => '']);

        $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=&hub.challenge=123')->assertForbidden();
    }

    public function test_handshake_does_not_reflect_markup_in_the_challenge(): void
    {
        $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=test-verify-token&hub.challenge='.urlencode('<script>alert(1)</script>'))
            ->assertForbidden();
    }

    // ---- signature --------------------------------------------------------------------------------------------

    public function test_invalid_signature_is_rejected_and_nothing_is_stored(): void
    {
        Queue::fake();
        [$tenant] = $this->provision('Acme');
        $this->connectMeta($tenant);

        $this->postWebhook($this->fixture('webhook_leadgen'), 'sha256='.str_repeat('0', 64))->assertForbidden();
        $this->postWebhook($this->fixture('webhook_leadgen'), 'garbage')->assertForbidden();

        $this->assertSame(0, MetaLeadEvent::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_missing_signature_header_is_rejected(): void
    {
        $body = json_encode($this->fixture('webhook_leadgen'));

        $this->call('POST', '/webhooks/meta', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertForbidden();
    }

    public function test_signature_must_cover_the_exact_raw_body(): void
    {
        [$tenant] = $this->provision('Acme');
        $this->connectMeta($tenant);
        $good = json_encode($this->fixture('webhook_leadgen'));
        $tampered = str_replace('900000000000001', '900000000000002', $good);

        $this->postWebhook($tampered, $this->signature($good))->assertForbidden();
        $this->assertSame(0, MetaLeadEvent::withoutGlobalScopes()->count());
    }

    public function test_signature_made_with_another_secret_is_rejected(): void
    {
        $body = json_encode($this->fixture('webhook_leadgen'));

        $this->postWebhook($body, 'sha256='.hash_hmac('sha256', $body, 'some-other-secret'))->assertForbidden();
    }

    public function test_unconfigured_app_secret_fails_closed(): void
    {
        config(['meta.app_secret' => '']);
        $body = json_encode($this->fixture('webhook_leadgen'));

        // Even a signature computed with an empty key must not be accepted.
        $this->postWebhook($body, 'sha256='.hash_hmac('sha256', $body, ''))->assertStatus(503);
        $this->assertSame(0, MetaLeadEvent::withoutGlobalScopes()->count());
    }

    public function test_oversized_body_is_rejected_before_parsing(): void
    {
        config(['meta.webhook_max_bytes' => 200]);
        $body = json_encode($this->fixture('webhook_leadgen'));

        $this->postWebhook($body)->assertStatus(413);
    }

    public function test_malformed_json_with_valid_signature_is_a_400(): void
    {
        $this->postWebhook('{not json')->assertStatus(400);
    }

    public function test_webhook_requires_no_csrf_token_or_session(): void
    {
        [$tenant] = $this->provision('Acme');
        $this->connectMeta($tenant);
        Queue::fake();

        $this->postWebhook($this->fixture('webhook_leadgen'))->assertOk()->assertSee('EVENT_RECEIVED');
    }

    // ---- persistence / tenancy --------------------------------------------------------------------------------

    public function test_valid_event_is_persisted_for_the_owning_tenant_and_dispatched(): void
    {
        Queue::fake();
        [$tenant] = $this->provision('Acme');
        $this->connectMeta($tenant);

        $this->postWebhook($this->fixture('webhook_leadgen'))->assertOk();

        $event = $this->eventFor($tenant);
        $this->assertSame($tenant->id, $event->tenant_id);
        $this->assertSame(MetaEventStatus::Received, $event->status);
        $this->assertSame('700000000000001', $event->form_id);
        $this->assertNotNull($event->correlation_id);
        Queue::assertPushed(ProcessMetaLeadEvent::class, fn ($job) => $job->tenantId() === $tenant->id && $job->eventPublicId === $event->public_id);
    }

    public function test_redelivery_is_idempotent(): void
    {
        Queue::fake();
        [$tenant] = $this->provision('Acme');
        $this->connectMeta($tenant);

        $this->postWebhook($this->fixture('webhook_leadgen'))->assertOk();
        $this->postWebhook($this->fixture('webhook_leadgen'))->assertOk();
        $this->postWebhook($this->fixture('webhook_leadgen'))->assertOk();

        $this->assertSame(1, MetaLeadEvent::withoutGlobalScopes()->count());
        Queue::assertPushed(ProcessMetaLeadEvent::class, 1);
    }

    public function test_unknown_page_is_quarantined_without_a_tenant_and_never_dispatched(): void
    {
        Queue::fake();
        $this->provision('Acme');

        $this->postWebhook($this->fixture('webhook_leadgen'))->assertOk();

        $event = MetaLeadEvent::withoutGlobalScopes()->first();
        $this->assertNull($event->tenant_id);
        $this->assertSame(MetaEventStatus::Quarantined, $event->status);
        Queue::assertNothingPushed();
    }

    public function test_tenant_is_resolved_from_our_page_record_not_from_the_payload(): void
    {
        Queue::fake();
        [$acme] = $this->provision('Acme');
        [$globex] = $this->provision('Globex');
        $this->connectMeta($acme, pageId: '100000000000001');
        $this->connectMeta($globex, pageId: '100000000000002');

        $payload = $this->fixture('webhook_leadgen');
        $payload['entry'][0]['changes'][0]['value']['page_id'] = '100000000000002';
        // Attacker-style extras that must be ignored:
        $payload['entry'][0]['changes'][0]['value']['tenant_id'] = $acme->id;
        $payload['tenant_id'] = $acme->id;

        $this->postWebhook($payload)->assertOk();

        $this->assertSame($globex->id, $this->eventFor($globex)->tenant_id);
    }

    public function test_non_page_objects_and_other_fields_are_ignored(): void
    {
        [$tenant] = $this->provision('Acme');
        $this->connectMeta($tenant);

        $this->postWebhook(['object' => 'instagram', 'entry' => []])->assertOk();
        $payload = $this->fixture('webhook_leadgen');
        $payload['entry'][0]['changes'][0]['field'] = 'feed';
        $this->postWebhook($payload)->assertOk();

        $this->assertSame(0, MetaLeadEvent::withoutGlobalScopes()->count());
    }

    public function test_non_numeric_ids_are_dropped(): void
    {
        [$tenant] = $this->provision('Acme');
        $this->connectMeta($tenant);
        $payload = $this->fixture('webhook_leadgen');
        $payload['entry'][0]['changes'][0]['value']['leadgen_id'] = '../../etc/passwd';

        $this->postWebhook($payload)->assertOk();

        $this->assertSame(0, MetaLeadEvent::withoutGlobalScopes()->count());
    }

    public function test_dispatch_failure_still_returns_200_and_leaves_a_recoverable_event(): void
    {
        [$tenant] = $this->provision('Acme');
        $this->connectMeta($tenant);
        $this->app->bind(Dispatcher::class, fn () => throw new \RuntimeException('queue down'));

        $this->postWebhook($this->fixture('webhook_leadgen'))->assertOk();

        $this->assertSame(MetaEventStatus::Received, $this->eventFor($tenant)->status);
    }

    public function test_webhook_floods_are_rate_limited(): void
    {
        config(['meta.app_secret' => $this->metaSecret]);
        for ($i = 0; $i < 600; $i++) {
            $this->postWebhook('{}', 'sha256=bad');
        }

        $this->postWebhook('{}', 'sha256=bad')->assertStatus(429);
    }
}
