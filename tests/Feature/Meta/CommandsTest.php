<?php

namespace Tests\Feature\Meta;

use App\Enums\MetaConnectionStatus;
use App\Enums\MetaEventStatus;
use App\Enums\TenantStatus;
use App\Jobs\Meta\ProcessMetaLeadEvent;
use App\Models\MetaLeadEvent;
use App\Models\MetaOauthState;
use App\Models\Tenant;
use App\Support\Facades\Tenancy;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MetaTestHelpers;
use Tests\TestCase;

class CommandsTest extends TestCase
{
    use MetaTestHelpers;

    private Tenant $tenant;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        [$this->tenant] = $this->provision('Acme');
    }

    private function event(array $attrs = []): MetaLeadEvent
    {
        $leadgen = (string) ++$this->counter;

        return Tenancy::withoutScope(fn () => MetaLeadEvent::query()->create($attrs + [
            'tenant_id' => $this->tenant->id, 'page_id' => '1', 'leadgen_id' => $leadgen,
            'status' => 'received', 'received_at' => now()->subMinutes(30),
        ]));
    }

    public function test_recover_redispatches_stale_received_events_only(): void
    {
        Queue::fake();
        $stale = $this->event();
        $this->event(['received_at' => now()]); // too fresh
        $this->event(['status' => 'imported']);

        $this->artisan('meta:recover-events')->assertSuccessful();

        Queue::assertPushed(ProcessMetaLeadEvent::class, 1);
        Queue::assertPushed(ProcessMetaLeadEvent::class, fn ($j) => $j->eventPublicId === $stale->public_id && $j->tenantId() === $this->tenant->id);
    }

    public function test_recover_respects_pending_retry_delay(): void
    {
        Queue::fake();
        $this->event(['attempts' => 2, 'last_attempt_at' => now()->subMinutes(30)]); // backoff may still be running
        $lost = $this->event(['attempts' => 2, 'last_attempt_at' => now()->subHours(3)]);

        $this->artisan('meta:recover-events')->assertSuccessful();

        Queue::assertPushed(ProcessMetaLeadEvent::class, 1);
        Queue::assertPushed(ProcessMetaLeadEvent::class, fn ($j) => $j->eventPublicId === $lost->public_id);
    }

    public function test_recover_resets_stuck_processing_events(): void
    {
        Queue::fake();
        $stuck = $this->event(['status' => 'processing', 'attempts' => 1, 'last_attempt_at' => now()->subHours(3)]);
        $busy = $this->event(['status' => 'processing', 'attempts' => 1, 'last_attempt_at' => now()->subMinutes(1)]);

        $this->artisan('meta:recover-events')->assertSuccessful();

        $this->assertSame(MetaEventStatus::Processing, Tenancy::withoutScope(fn () => $busy->fresh())->status);
        $this->assertSame(MetaEventStatus::Received, Tenancy::withoutScope(fn () => $stuck->fresh())->status);
    }

    public function test_recover_skips_suspended_tenants_and_quarantined_events(): void
    {
        Queue::fake();
        $this->event();
        $this->event(['tenant_id' => null, 'status' => 'quarantined']);
        $this->tenant->update(['status' => TenantStatus::Suspended]);

        $this->artisan('meta:recover-events')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_recover_never_crashes_when_the_queue_is_down(): void
    {
        $this->event();
        $this->app->bind(Dispatcher::class, fn () => throw new \RuntimeException('down'));

        $this->artisan('meta:recover-events')->assertSuccessful();
    }

    public function test_prune_deletes_old_settled_history_and_keeps_work_in_flight(): void
    {
        $old = now()->subDays(100);
        $gone = [$this->event(['status' => 'imported', 'received_at' => $old]), $this->event(['status' => 'failed', 'received_at' => $old]), $this->event(['tenant_id' => null, 'status' => 'quarantined', 'received_at' => $old])];
        $kept = [$this->event(['status' => 'imported', 'received_at' => now()->subDays(10)]), $this->event(['status' => 'needs_review', 'received_at' => $old]), $this->event(['status' => 'received', 'received_at' => $old])];
        Tenancy::runAs($this->tenant, fn () => MetaOauthState::query()->create(['user_id' => $this->tenant->memberships()->first()->user_id, 'state_hash' => str_repeat('a', 64), 'nonce_hash' => str_repeat('b', 64), 'expires_at' => now()->subDays(3)]));

        $this->artisan('meta:prune')->assertSuccessful();

        foreach ($gone as $e) {
            $this->assertNull(Tenancy::withoutScope(fn () => MetaLeadEvent::query()->find($e->id)));
        }
        foreach ($kept as $e) {
            $this->assertNotNull(Tenancy::withoutScope(fn () => MetaLeadEvent::query()->find($e->id)));
        }
        $this->assertSame(0, MetaOauthState::withoutGlobalScopes()->count());
    }

    public function test_validate_command_flags_only_the_broken_connection_and_skips_suspended_tenants(): void
    {
        [$ok] = $this->connectMeta($this->tenant, pageId: '100000000000001');
        [$other] = $this->provision('Globex');
        [$bad] = $this->connectMeta($other, pageId: '100000000000002');
        [$suspended] = $this->provision('Dormant');
        [$sus] = $this->connectMeta($suspended, pageId: '100000000000003');
        $suspended->update(['status' => TenantStatus::Suspended]);
        Http::fake(function ($request) {
            $valid = str_contains($request->url(), 'PAGE-TOKEN-100000000000001');

            return Http::response(['data' => ['is_valid' => $valid, 'scopes' => config('meta.oauth_scopes')]]);
        });

        $this->artisan('meta:validate-connections')->assertSuccessful();

        $this->assertSame(MetaConnectionStatus::Active, Tenancy::runAs($this->tenant, fn () => $ok->fresh()->status));
        $this->assertSame(MetaConnectionStatus::ReauthorizationRequired, Tenancy::runAs($other, fn () => $bad->fresh()->status));
        $this->assertSame(MetaConnectionStatus::Active, Tenancy::runAs($suspended, fn () => $sus->fresh()->status));
        Http::assertSentCount(2);
    }

    public function test_simulate_webhook_posts_a_validly_signed_mock_event(): void
    {
        $this->connectMeta($this->tenant);
        Http::fake(['*' => Http::response('EVENT_RECEIVED')]);

        $this->artisan('meta:simulate-webhook', ['page_id' => '100000000000001', 'leadgen_id' => '555', '--form_id' => '700000000000001'])->assertSuccessful();

        Http::assertSent(function ($request) {
            $expected = 'sha256='.hash_hmac('sha256', $request->body(), $this->metaSecret);

            return $request->hasHeader('X-Hub-Signature-256', $expected) && str_contains($request->body(), '"leadgen_id":"555"');
        });
    }

    public function test_simulate_webhook_refuses_in_production(): void
    {
        $this->app['env'] = 'production';
        Http::fake();

        $this->artisan('meta:simulate-webhook', ['page_id' => '1', 'leadgen_id' => '2'])->assertFailed();

        Http::assertNothingSent();
    }
}
