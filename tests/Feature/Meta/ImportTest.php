<?php

namespace Tests\Feature\Meta;

use App\Actions\Leads\CreateLead;
use App\Actions\Meta\ImportMetaLead;
use App\Enums\MetaConnectionStatus;
use App\Enums\MetaEventStatus;
use App\Enums\RoleName;
use App\Enums\TenantStatus;
use App\Exceptions\MetaApiException;
use App\Jobs\Meta\ProcessMetaLeadEvent;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\MetaConnection;
use App\Models\MetaLeadEvent;
use App\Models\MetaPage;
use App\Models\Tenant;
use App\Services\Leads\PhoneNormalizer;
use App\Support\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\MetaTestHelpers;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use MetaTestHelpers;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        [$this->tenant] = $this->provision('Acme');
    }

    /** Receive the fixture webhook (job not run) and return the stored event. */
    private function receive(?Tenant $tenant = null, ?array $payload = null): MetaLeadEvent
    {
        config(['queue.default' => 'database']); // park the dispatched job in the jobs table; tests run it explicitly
        $this->postWebhook($payload ?? $this->fixture('webhook_leadgen'))->assertOk();

        return $this->eventFor($tenant ?? $this->tenant);
    }

    private function process(MetaLeadEvent $event): void
    {
        ProcessMetaLeadEvent::dispatchSync($event->public_id, $event->tenant_id);
    }

    private function fresh(MetaLeadEvent $event): MetaLeadEvent
    {
        return Tenancy::withoutScope(fn () => $event->fresh());
    }

    private function leads(): Collection
    {
        return $this->runIn($this->tenant, fn () => Lead::query()->get());
    }

    public function test_imports_a_lead_with_mapped_fields_and_provenance(): void
    {
        $this->connectMeta($this->tenant);
        $this->fakeGraphLead();
        $event = $this->receive();

        $this->process($event);

        $event = $this->fresh($event);
        $this->assertSame(MetaEventStatus::Imported, $event->status);
        $this->assertSame('created', $event->outcome);
        $lead = $this->leads()->sole();
        $this->assertSame('Asha Verma', $lead->full_name);
        $this->assertSame('asha.verma@example.test', $lead->email);
        $this->assertSame('+919876543210', $lead->phone_e164);
        $this->assertSame('Verma Traders', $lead->company_name);
        $this->assertSame('meta', $lead->external_provider);
        $this->assertSame('900000000000001', $lead->external_id);
        $this->assertSame($this->sourceId($this->tenant, 'facebook_ads'), $lead->lead_source_id);
        $this->assertSame($this->stageId($this->tenant, 'new'), $lead->pipeline_stage_id);
        $this->assertNull($lead->created_by);
        $this->assertSame($lead->id, $event->lead_id);
        $this->assertNotNull($event->processed_at);

        $note = $this->runIn($this->tenant, fn () => LeadActivity::query()->where('lead_id', $lead->id)->sole());
        $this->assertNull($note->user_id);
        $this->assertStringContainsString('Diwali 2026', $note->body);
        $this->assertStringContainsString('budget_range: 50k-1L', $note->body);
        $this->assertStringNotContainsString('Asha.Verma@Example.test', $note->body);
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'meta.lead.imported')->where('tenant_id', $this->tenant->id)->exists());
    }

    public function test_default_owner_is_applied_when_active(): void
    {
        $agent = $this->member($this->tenant, RoleName::SalesAgent);
        $this->connectMeta($this->tenant, ownerId: $agent->id);
        $this->fakeGraphLead();
        $event = $this->receive();

        $this->process($event);

        $this->assertSame($agent->id, $this->leads()->sole()->owner_id);
    }

    public function test_invalid_phone_and_email_are_dropped_with_warnings_not_stored(): void
    {
        $this->connectMeta($this->tenant);
        $lead = $this->fixture('lead_full');
        $lead['field_data'] = [
            ['name' => 'full_name', 'values' => ['Bad Data']],
            ['name' => 'email', 'values' => ['not-an-email']],
            ['name' => 'phone_number', 'values' => ['12']],
        ];
        $this->fakeGraphLead($lead);
        $event = $this->receive();

        $this->process($event);

        $stored = $this->leads()->sole();
        $this->assertNull($stored->email);
        $this->assertNull($stored->phone_e164);
        $this->assertEqualsCanonicalizing(['email_invalid', 'phone_invalid'], $this->fresh($event)->warnings);
    }

    public function test_lead_without_a_name_gets_a_placeholder_and_a_warning(): void
    {
        $this->connectMeta($this->tenant);
        $lead = $this->fixture('lead_full');
        $lead['field_data'] = [['name' => 'phone_number', 'values' => ['9876543210']]];
        $this->fakeGraphLead($lead);
        $event = $this->receive();

        $this->process($event);

        $this->assertSame('+919876543210', $this->leads()->sole()->full_name);
        $this->assertContains('name_missing', $this->fresh($event)->warnings);
    }

    public function test_custom_field_map_points_targets_at_other_question_keys(): void
    {
        [, , $mapping] = $this->connectMeta($this->tenant);
        $this->runIn($this->tenant, fn () => $mapping->update(['field_map' => ['email' => 'work_mail', 'full_name' => 'your_name']]));
        $lead = $this->fixture('lead_full');
        $lead['field_data'] = [
            ['name' => 'your_name', 'values' => ['Custom Name']],
            ['name' => 'work_mail', 'values' => ['custom@example.test']],
        ];
        $this->fakeGraphLead($lead);
        $event = $this->receive();

        $this->process($event);

        $stored = $this->leads()->sole();
        $this->assertSame('Custom Name', $stored->full_name);
        $this->assertSame('custom@example.test', $stored->email);
    }

    public function test_possible_duplicate_is_flagged_and_nothing_is_created_or_overwritten(): void
    {
        $this->connectMeta($this->tenant);
        $existing = $this->lead($this->tenant, ['full_name' => 'Existing Asha', 'phone_e164' => '+919876543210', 'email' => null]);
        $this->fakeGraphLead();
        $event = $this->receive();

        $this->process($event);

        $event = $this->fresh($event);
        $this->assertSame(MetaEventStatus::NeedsReview, $event->status);
        $this->assertSame($existing->id, $event->matched_lead_id);
        $this->assertNull($event->lead_id);
        $this->assertCount(1, $this->leads());
        $this->assertSame('Existing Asha', $existing->fresh()->full_name);
        $this->assertSame('possible_duplicate', $event->outcome);
    }

    public function test_confirmed_duplicate_is_created_on_the_next_run(): void
    {
        $this->connectMeta($this->tenant);
        $this->lead($this->tenant, ['phone_e164' => '+919876543210']);
        $this->fakeGraphLead();
        $event = $this->receive();
        $this->process($event);
        Tenancy::withoutScope(fn () => MetaLeadEvent::query()->whereKey($event->id)->update(['status' => 'received', 'confirm_duplicate' => true]));

        $this->process($event);

        $this->assertSame(MetaEventStatus::Imported, $this->fresh($event)->status);
        $this->assertCount(2, $this->leads());
    }

    public function test_running_the_same_event_twice_creates_one_lead(): void
    {
        $this->connectMeta($this->tenant);
        $this->fakeGraphLead();
        $event = $this->receive();

        $this->process($event);
        $this->process($event);

        $this->assertCount(1, $this->leads());
        Http::assertSentCount(1);
    }

    public function test_a_lead_that_already_exists_for_the_meta_id_is_reported_as_already_imported(): void
    {
        $this->connectMeta($this->tenant);
        $this->lead($this->tenant, ['external_provider' => 'meta', 'external_id' => '900000000000001', 'phone_e164' => '+919000000000']);
        $this->fakeGraphLead();
        $event = $this->receive();

        $this->process($event);

        $event = $this->fresh($event);
        $this->assertSame(MetaEventStatus::Imported, $event->status);
        $this->assertSame('already_imported', $event->outcome);
        $this->assertCount(1, $this->leads());
    }

    public function test_database_unique_key_blocks_a_second_lead_for_the_same_meta_id(): void
    {
        $this->lead($this->tenant, ['external_provider' => 'meta', 'external_id' => '1']);

        $this->expectException(QueryException::class);
        $this->lead($this->tenant, ['external_provider' => 'meta', 'external_id' => '1']);
    }

    public function test_same_meta_id_in_two_tenants_is_allowed(): void
    {
        [$other] = $this->provision('Globex');
        $this->lead($this->tenant, ['external_provider' => 'meta', 'external_id' => '1']);
        $this->lead($other, ['external_provider' => 'meta', 'external_id' => '1']);

        $this->assertTrue(true);
    }

    public function test_form_without_enabled_mapping_is_ignored_and_never_fetched(): void
    {
        $this->connectMeta($this->tenant, enabled: false);
        $this->fakeGraphLead();
        $event = $this->receive();

        $this->process($event);

        $this->assertSame(MetaEventStatus::Ignored, $this->fresh($event)->status);
        $this->assertCount(0, $this->leads());
        Http::assertNothingSent();
    }

    public function test_unmapped_form_is_ignored(): void
    {
        $this->connectMeta($this->tenant, formId: '700000000000099');
        $this->fakeGraphLead();
        $event = $this->receive();

        $this->process($event);

        $this->assertSame(MetaEventStatus::Ignored, $this->fresh($event)->status);
    }

    public function test_disconnected_connection_fails_the_event_without_calling_meta(): void
    {
        [$connection] = $this->connectMeta($this->tenant);
        $this->fakeGraphLead();
        $event = $this->receive();
        $this->runIn($this->tenant, fn () => $connection->update(['status' => MetaConnectionStatus::Disconnected]));

        $this->process($event);

        $event = $this->fresh($event);
        $this->assertSame(MetaEventStatus::Failed, $event->status);
        $this->assertSame('connection_inactive', $event->failure_code);
        Http::assertNothingSent();
    }

    public function test_suspended_tenant_job_does_nothing(): void
    {
        $this->connectMeta($this->tenant);
        $this->fakeGraphLead();
        $event = $this->receive();
        $this->tenant->update(['status' => TenantStatus::Suspended]);

        try {
            $this->process($event);
        } catch (\Throwable) {
        }

        $event = $this->fresh($event);
        $this->assertSame(MetaEventStatus::Failed, $event->status); // parked and replayable once the workspace is active again
        $this->assertSame('tenant_unavailable', $event->failure_code);
        Http::assertNothingSent();
        $this->assertSame(0, Lead::withoutGlobalScopes()->count());
    }

    public function test_job_for_another_tenant_cannot_see_the_event(): void
    {
        [$other] = $this->provision('Globex');
        $this->connectMeta($this->tenant);
        $this->fakeGraphLead();
        $event = $this->receive();

        ProcessMetaLeadEvent::dispatchSync($event->public_id, $other->id);

        $this->assertSame(MetaEventStatus::Received, $this->fresh($event)->status);
        Http::assertNothingSent();
    }

    public function test_graph_returning_a_different_lead_id_is_rejected(): void
    {
        $this->connectMeta($this->tenant);
        $other = $this->fixture('lead_full');
        $other['id'] = '999';
        $this->fakeGraphLead($other);
        $event = $this->receive();

        $this->process($event);

        $this->assertSame('lead_mismatch', $this->fresh($event)->failure_code);
        $this->assertCount(0, $this->leads());
    }

    public function test_auth_error_flags_reauthorization_and_parks_the_event(): void
    {
        [$connection] = $this->connectMeta($this->tenant);
        Http::fake(['graph.example.test/*' => Http::response(['error' => ['code' => 190, 'message' => 'Error validating access token: user 4242 secret EAAB'.str_repeat('x', 60)]], 401)]);
        $event = $this->receive();

        $this->process($event);

        $event = $this->fresh($event);
        $this->assertSame(MetaEventStatus::Failed, $event->status);
        $this->assertSame('meta_auth_190', $event->failure_code);
        $this->assertStringNotContainsString('EAAB', (string) $event->failure_summary);
        $this->assertSame(MetaConnectionStatus::ReauthorizationRequired, Tenancy::runAs($this->tenant, fn () => $connection->fresh()->status));
    }

    public function test_permission_error_fails_without_deauthorizing_the_connection(): void
    {
        [$connection] = $this->connectMeta($this->tenant);
        Http::fake(['graph.example.test/*' => Http::response(['error' => ['code' => 10, 'message' => 'x']], 403)]);
        $event = $this->receive();

        $this->process($event);

        $this->assertSame('meta_permission_10', $this->fresh($event)->failure_code);
        $this->assertSame(MetaConnectionStatus::Active, Tenancy::runAs($this->tenant, fn () => $connection->fresh()->status));
    }

    public function test_replayed_failed_event_imports_after_the_cause_is_fixed(): void
    {
        $this->connectMeta($this->tenant);
        Http::fake(['graph.example.test/*' => Http::sequence()->push(['error' => ['code' => 10]], 403)->push($this->fixture('lead_full'))]);
        $event = $this->receive();
        $this->process($event);
        $this->assertSame(MetaEventStatus::Failed, $this->fresh($event)->status);

        Tenancy::withoutScope(fn () => MetaLeadEvent::query()->whereKey($event->id)->update(['status' => 'received']));
        $this->process($event);

        $this->assertSame(MetaEventStatus::Imported, $this->fresh($event)->status);
    }

    public function test_transient_error_returns_event_to_received_and_releases_the_job_with_backoff(): void
    {
        $this->connectMeta($this->tenant);
        Http::fake(['graph.example.test/*' => Http::response('', 503)]);
        $event = $this->receive();

        $job = (new ProcessMetaLeadEvent($event->public_id, $this->tenant->id))->withFakeQueueInteractions();
        Tenancy::runAs($this->tenant, fn () => $job->handle(app(ImportMetaLead::class)));

        $job->assertReleased();
        $this->assertSame(MetaEventStatus::Received, $this->fresh($event)->status);
        $this->assertSame(1, $this->fresh($event)->attempts);
        $this->assertCount(0, $this->leads());
    }

    public function test_rate_limit_release_honours_retry_after(): void
    {
        $this->connectMeta($this->tenant);
        Http::fake(['graph.example.test/*' => Http::response(['error' => ['code' => 4]], 429, ['Retry-After' => '900'])]);
        $event = $this->receive();

        $job = (new ProcessMetaLeadEvent($event->public_id, $this->tenant->id))->withFakeQueueInteractions();
        Tenancy::runAs($this->tenant, fn () => $job->handle(app(ImportMetaLead::class)));

        $job->assertReleased();
        $delay = $job->job->releaseDelay;
        $this->assertGreaterThanOrEqual(900, (int) $delay);
        $this->assertLessThanOrEqual(3600, (int) $delay);
    }

    public function test_exhausted_retries_park_the_event_as_failed_and_replayable(): void
    {
        $this->connectMeta($this->tenant);
        Http::fake(['graph.example.test/*' => Http::response('', 503)]);
        $event = $this->receive();
        $job = new ProcessMetaLeadEvent($event->public_id, $this->tenant->id);

        $job->failed(new MetaApiException(MetaApiException::TRANSIENT, 503));

        $event = $this->fresh($event);
        $this->assertSame(MetaEventStatus::Failed, $event->status);
        $this->assertTrue($event->status->isReplayable());
    }

    public function test_unexpected_crash_hands_the_event_back(): void
    {
        $this->connectMeta($this->tenant);
        $this->fakeGraphLead();
        $event = $this->receive();
        $this->app->bind(PhoneNormalizer::class, fn () => null);
        $boom = new class extends CreateLead
        {
            public function import(array $data, bool $confirmDuplicate = false): array
            {
                throw new \RuntimeException('db exploded');
            }
        };
        $this->app->instance(CreateLead::class, $boom);

        try {
            Tenancy::runAs($this->tenant, fn () => (new ProcessMetaLeadEvent($event->public_id, $this->tenant->id))->handle(app(ImportMetaLead::class)));
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('db exploded', $e->getMessage());
        }

        $this->assertSame(MetaEventStatus::Received, $this->fresh($event)->status);
        $this->assertCount(0, $this->leads());
    }

    public function test_tokens_and_personal_data_never_reach_the_logs(): void
    {
        $this->connectMeta($this->tenant);
        $this->fakeGraphLead();
        $logged = [];
        Log::listen(function ($m) use (&$logged) {
            $logged[] = $m->message.json_encode($m->context);
        });
        $event = $this->receive();

        $this->process($event);

        $all = implode("\n", $logged);
        $this->assertNotSame('', $all);
        foreach (['PAGE-TOKEN', 'Asha', 'asha.verma', '9876543210', $this->metaSecret] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }

    public function test_page_token_is_encrypted_at_rest(): void
    {
        $this->connectMeta($this->tenant);

        $raw = \DB::table('meta_pages')->value('access_token');

        $this->assertStringNotContainsString('PAGE-TOKEN', $raw);
        $this->assertSame('PAGE-TOKEN-100000000000001', Tenancy::runAs($this->tenant, fn () => MetaPage::query()->first()->access_token));
    }

    public function test_graph_requests_carry_the_token_in_a_header_with_appsecret_proof(): void
    {
        $this->connectMeta($this->tenant);
        $this->fakeGraphLead();
        $event = $this->receive();

        $this->process($event);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v99.0/900000000000001')
                && $request->hasHeader('Authorization', 'Bearer PAGE-TOKEN-100000000000001')
                && ! str_contains($request->url(), 'PAGE-TOKEN')
                && str_contains($request->url(), 'appsecret_proof='.hash_hmac('sha256', 'PAGE-TOKEN-100000000000001', $this->metaSecret));
        });
    }

    public function test_connection_model_hides_pending_assets(): void
    {
        [$connection] = $this->connectMeta($this->tenant);
        Tenancy::runAs($this->tenant, fn () => $connection->update(['pending_assets' => [['id' => '1', 'access_token' => 'SECRET']]]));

        $json = Tenancy::runAs($this->tenant, fn () => MetaConnection::query()->first()->toJson());

        $this->assertStringNotContainsString('SECRET', $json);
        $this->assertStringNotContainsString('"pending_assets"', $json);
    }
}
