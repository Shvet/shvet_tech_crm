<?php

namespace Tests\Feature\Meta;

use App\Actions\Meta\ImportMetaLead;
use App\Actions\Meta\UpdateFormMapping;
use App\Enums\MetaEventStatus;
use App\Enums\RoleName;
use App\Exceptions\MetaApiException;
use App\Exceptions\MetaConnectionException;
use App\Jobs\Meta\ProcessMetaLeadEvent;
use App\Models\MetaLeadEvent;
use App\Models\MetaOauthState;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Meta\OAuthStateService;
use App\Support\Facades\Tenancy;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\MetaTestHelpers;
use Tests\TestCase;

/**
 * Each protection is tested in isolation, with the other layers neutralised by the test setup, so removing any single
 * one fails a test (verified by mutation). Overlapping layers otherwise mask each other.
 */
class ProtectionLayersTest extends TestCase
{
    use MetaTestHelpers;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        [$this->tenant, $this->admin] = $this->provision('Acme');
    }

    private function newSession(): Store
    {
        return new Store('t', new ArraySessionHandler(120));
    }

    private function sign(string $token, int $userId): string
    {
        return hash_hmac('sha256', $token.'|'.$this->tenant->id.'|'.$userId, config('app.key'));
    }

    /** Insert a state row directly and return [state string, session holding the matching nonce]. */
    private function plant(array $row = [], ?int $signedFor = null, string $nonce = 'N1'): array
    {
        $token = bin2hex(random_bytes(8));
        Tenancy::runAs($this->tenant, fn () => MetaOauthState::query()->create($row + [
            'user_id' => $this->admin->id, 'state_hash' => hash('sha256', $token), 'nonce_hash' => hash('sha256', 'N1'), 'expires_at' => now()->addMinutes(5),
        ]));
        $session = $this->newSession();
        $session->put('meta_oauth_nonce', $nonce);

        return [$token.'.'.$this->sign($token, $signedFor ?? $this->admin->id), $session];
    }

    private function consume(string $state, Store $session, ?User $user = null): void
    {
        Tenancy::runAs($this->tenant, fn () => app(OAuthStateService::class)->consume($state, $user ?? $this->admin, $session));
    }

    public function test_a_well_formed_state_is_accepted_once(): void
    {
        [$state, $session] = $this->plant();
        $this->consume($state, $session);

        $session->put('meta_oauth_nonce', 'N1'); // even with the right nonce again, the row is spent
        $this->expectException(MetaConnectionException::class);
        $this->consume($state, $session);
    }

    public function test_used_state_is_rejected(): void
    {
        [$state, $session] = $this->plant(['used_at' => now()]);

        $this->expectException(MetaConnectionException::class);
        $this->consume($state, $session);
    }

    public function test_expired_state_is_rejected(): void
    {
        [$state, $session] = $this->plant(['expires_at' => now()->subSecond()]);

        $this->expectException(MetaConnectionException::class);
        $this->consume($state, $session);
    }

    public function test_state_row_of_another_user_is_rejected_even_with_a_valid_signature(): void
    {
        $other = $this->member($this->tenant, RoleName::Admin);
        [$state, $session] = $this->plant(['user_id' => $other->id], signedFor: $this->admin->id);

        $this->expectException(MetaConnectionException::class);
        $this->consume($state, $session);
    }

    public function test_wrong_browser_nonce_is_rejected(): void
    {
        [$state, $session] = $this->plant(nonce: 'DIFFERENT');

        $this->expectException(MetaConnectionException::class);
        $this->consume($state, $session);
    }

    public function test_bad_signature_is_rejected_even_when_the_row_exists(): void
    {
        [$state, $session] = $this->plant();
        $state = explode('.', $state)[0].'.'.str_repeat('0', 64);

        $this->expectException(MetaConnectionException::class);
        $this->consume($state, $session);
    }

    public function test_row_flipped_between_check_and_claim_is_not_double_spent(): void
    {
        [$state, $session] = $this->plant();
        $token = explode('.', $state)[0];
        // Simulate the race: another request spends the row after our SELECT but before our UPDATE.
        MetaOauthState::retrieved(function (MetaOauthState $row) {
            MetaOauthState::withoutGlobalScopes()->whereKey($row->getKey())->update(['used_at' => now()]);
        });

        try {
            $this->consume($state, $session);
            $this->fail('the second claimant must lose');
        } catch (MetaConnectionException) {
            $this->assertNotNull($token);
        } finally {
            MetaOauthState::flushEventListeners();
        }
    }

    public function test_importer_claim_is_atomic_for_a_stale_model(): void
    {
        [, $page] = $this->connectMeta($this->tenant);
        $this->fakeGraphLead();
        config(['queue.default' => 'database']);
        $this->postWebhook($this->fixture('webhook_leadgen'))->assertOk();
        $stale = $this->eventFor($this->tenant); // status "received" in memory
        Tenancy::withoutScope(fn () => MetaLeadEvent::query()->whereKey($stale->id)->update(['status' => 'processing']));

        Tenancy::runAs($this->tenant, fn () => app(ImportMetaLead::class)($stale));

        Http::assertNothingSent(); // someone else owns it: we must not fetch or import
        $this->assertSame(MetaEventStatus::Processing, Tenancy::withoutScope(fn () => $stale->fresh())->status);
    }

    public function test_mapping_action_rejects_foreign_or_inactive_references_on_its_own(): void
    {
        [$other, $otherAdmin] = $this->provision('Globex');
        [, , $mapping] = $this->connectMeta($this->tenant);
        $good = ['is_enabled' => true, 'lead_source_id' => $this->sourceId($this->tenant), 'pipeline_stage_id' => $this->stageId($this->tenant, 'new')];
        $update = fn (array $d) => Tenancy::runAs($this->tenant, fn () => app(UpdateFormMapping::class)($mapping->fresh(), $d));

        foreach ([['lead_source_id' => $this->sourceId($other)], ['pipeline_stage_id' => $this->stageId($other, 'new')], ['default_owner_id' => $otherAdmin->id]] as $bad) {
            try {
                $update(array_merge($good, $bad));
                $this->fail('accepted '.json_encode($bad));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_job_gives_up_after_the_configured_number_of_tries(): void
    {
        [, $page] = $this->connectMeta($this->tenant);
        Http::fake(['graph.example.test/*' => Http::response('', 503)]);
        config(['queue.default' => 'database']);
        $this->postWebhook($this->fixture('webhook_leadgen'))->assertOk();
        $event = $this->eventFor($this->tenant);

        $job = (new ProcessMetaLeadEvent($event->public_id, $this->tenant->id))->withFakeQueueInteractions();
        $job->job->attempts = $job->tries;
        Tenancy::runAs($this->tenant, fn () => $job->handle(app(ImportMetaLead::class)));

        $job->assertFailed();
        $job->assertNotReleased();
    }

    public function test_api_exception_summary_has_codes_only(): void
    {
        $e = new MetaApiException(MetaApiException::AUTH, 401, 190, 458, null, 'TRACE');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9 _:.,()=\/-]+$/', $e->getMessage());
        $this->assertStringNotContainsString('://', $e->getMessage());
    }
}
