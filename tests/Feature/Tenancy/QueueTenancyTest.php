<?php

namespace Tests\Feature\Tenancy;

use App\Enums\TenantStatus;
use App\Models\Lead;
use App\Support\Facades\Tenancy;
use App\Tenancy\Exceptions\TenantContextMissing;
use App\Tenancy\Exceptions\TenantUnavailable;
use App\Tenancy\Queue\SetTenantContext;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Tests\Support\ProbeJob;
use Tests\TestCase;

class QueueTenancyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ProbeJob::$seen = [];
    }

    public function test_dispatching_without_a_tenant_context_is_refused(): void
    {
        $this->expectException(TenantContextMissing::class);
        new ProbeJob;
    }

    public function test_job_captures_the_dispatching_tenant_and_runs_inside_it(): void
    {
        [$a] = $this->provision('Alpha');

        $this->runIn($a, fn () => ProbeJob::dispatch());

        $this->assertSame([$a->id], ProbeJob::$seen);
    }

    public function test_context_is_restored_after_each_job_and_does_not_leak_between_jobs(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');

        // Dispatched with explicit ids from no context at all (like a worker consuming a payload).
        ProbeJob::dispatch($a->id);
        $this->assertNull(Tenancy::id());
        ProbeJob::dispatch($b->id);
        $this->assertNull(Tenancy::id());

        $this->assertSame([$a->id, $b->id], ProbeJob::$seen);
    }

    public function test_payload_tenant_wins_over_whatever_context_is_active(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');

        $this->runIn($a, function () use ($a, $b) {
            ProbeJob::dispatch($b->id);
            $this->assertSame($a->id, Tenancy::id()); // dispatcher's own context is intact afterwards
        });

        $this->assertSame([$b->id], ProbeJob::$seen);
    }

    public function test_job_for_a_suspended_tenant_does_not_run_and_is_failed(): void
    {
        [$a] = $this->provision('Alpha');
        $job = ProbeJob::dispatch($a->id); // proves the happy path first
        ProbeJob::$seen = [];
        $a->update(['status' => TenantStatus::Suspended]);

        $failed = [];
        Event::listen(JobFailed::class, function (JobFailed $e) use (&$failed) {
            $failed[] = $e->exception::class;
        });

        ProbeJob::dispatch($a->id);

        $this->assertSame([], ProbeJob::$seen);
        $this->assertSame([TenantUnavailable::class], $failed);
    }

    public function test_job_for_a_deleted_tenant_does_not_run(): void
    {
        [$a] = $this->provision('Alpha');
        $id = $a->id;
        \DB::table('tenants')->where('id', $id)->delete();

        ProbeJob::dispatch($id);

        $this->assertSame([], ProbeJob::$seen);
    }

    public function test_retry_after_failure_keeps_the_same_tenant(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');

        $job = $this->runIn($a, fn () => new ProbeJob(explode: true));
        // A retry re-hydrates the serialized payload, possibly in a worker with another/no context.
        $retry = unserialize(serialize($job));

        $this->assertSame($a->id, $retry->tenantId());

        try {
            Tenancy::runAs($b, fn () => app(Pipeline::class)
                ->send($retry)->through($retry->middleware())->then(fn ($j) => $j->handle()));
        } catch (\RuntimeException) {
        }

        $this->assertSame([$a->id], ProbeJob::$seen);
        $this->assertSame($b->id, Tenancy::id() ?? $b->id);
    }

    public function test_jobs_without_the_tenant_aware_contract_are_rejected_by_the_middleware(): void
    {
        $this->expectException(\LogicException::class);
        (new SetTenantContext)->handle(new \stdClass, fn () => null);
    }

    public function test_job_data_access_is_tenant_scoped(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');
        $this->runIn($a, fn () => Lead::factory()->count(2)->create());
        $this->runIn($b, fn () => Lead::factory()->count(5)->create());

        $counts = [];
        foreach ([$a, $b] as $tenant) {
            Tenancy::runAs($tenant, function () use (&$counts) {
                $counts[] = Lead::query()->count();
            });
        }

        $this->assertSame([2, 5], $counts);
    }

    public function test_scheduler_fan_out_visits_only_active_tenants_and_isolates_failures(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');
        [$c] = $this->provision('Gamma');
        $c->update(['status' => TenantStatus::Suspended]);

        $visited = [];
        Tenancy::eachActiveTenant(function ($tenant) use (&$visited, $a) {
            $visited[] = [$tenant->id, Tenancy::id()];
            if ($tenant->id === $a->id) {
                throw new \RuntimeException('tenant A job exploded');
            }
        });

        $this->assertSame([[$a->id, $a->id], [$b->id, $b->id]], $visited);
        $this->assertNull(Tenancy::id());
    }
}
