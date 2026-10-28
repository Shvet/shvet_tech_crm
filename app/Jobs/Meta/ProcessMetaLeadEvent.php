<?php

namespace App\Jobs\Meta;

use App\Actions\Meta\ImportMetaLead;
use App\Enums\MetaEventStatus;
use App\Exceptions\MetaApiException;
use App\Models\MetaLeadEvent;
use App\Models\Tenant;
use App\Services\Meta\MetaLog;
use App\Support\Facades\Tenancy;
use App\Tenancy\Exceptions\TenantUnavailable;
use App\Tenancy\Queue\QueuesForTenant;
use App\Tenancy\Queue\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Imports one Meta lead event. Carries ONLY the tenant id and the event's public id (no lead data, no tokens).
 * The tenant is re-validated by SetTenantContext at run time; the connection/page/mapping are re-validated by the importer.
 *
 * Retry policy: bounded tries; the delay follows config('meta.job.backoff') plus jitter, or Meta's own retry hint when
 * it asks for a longer wait. When tries run out the event is parked as "failed" (replayable by an admin).
 */
class ProcessMetaLeadEvent implements ShouldQueue, TenantAware
{
    use Queueable, QueuesForTenant;

    public int $tries;

    public int $timeout = 60;

    public function __construct(public string $eventPublicId, ?int $tenantId = null)
    {
        $this->captureTenant($tenantId);
        $this->tries = (int) config('meta.job.tries', 6);
    }

    public function handle(ImportMetaLead $import): void
    {
        $event = MetaLeadEvent::query()->where('public_id', $this->eventPublicId)->first();

        // Gone, or not this tenant's (scope hides it): nothing to do, and no information leaks.
        if (! $event || $event->status !== MetaEventStatus::Received) {
            return;
        }

        try {
            $import($event);
        } catch (MetaApiException $e) {
            $this->retryLater($e);
        } catch (Throwable $e) {
            // Unexpected crash mid-import: hand the event back so the framework's retry (or the recovery command) can claim it.
            MetaLeadEvent::query()->whereKey($event->getKey())->where('status', MetaEventStatus::Processing->value)
                ->update(['status' => MetaEventStatus::Received->value]);
            throw $e;
        }
    }

    private function retryLater(MetaApiException $e): void
    {
        if ($this->attempts() >= $this->tries) {
            $this->fail($e);

            return;
        }

        $schedule = (array) config('meta.job.backoff', [30, 120, 600, 1800, 3600]);
        $base = (int) ($schedule[min($this->attempts() - 1, count($schedule) - 1)] ?? 60);
        $base = max($base, (int) $e->retryAfterSeconds);
        $delay = min($base + random_int(0, (int) max(1, $base * 0.2)), (int) config('meta.job.max_release_seconds', 3600));

        MetaLog::info('job.retry', ['event' => $this->eventPublicId, 'attempt' => $this->attempts(), 'delay' => $delay, 'code' => $e->code()]);
        $this->release($delay);
    }

    /** Retries exhausted or unexpected crash: park the event so it is visible and replayable. */
    public function failed(Throwable $e): void
    {
        Tenancy::runAs(Tenancy::withoutScope(fn () => Tenant::query()->find($this->tenantId())) ?? throw $e, function () use ($e) {
            [$code, $summary] = match (true) {
                $e instanceof MetaApiException => [$e->code(), $e->adminMessage()],
                $e instanceof TenantUnavailable => ['tenant_unavailable', 'The workspace was not active when this import ran. Retry once it is active again.'],
                default => ['job_failed', 'Import failed unexpectedly; it can be retried.'],
            };

            MetaLeadEvent::query()->where('public_id', $this->eventPublicId)
                ->whereIn('status', [MetaEventStatus::Received->value, MetaEventStatus::Processing->value])
                ->update(['status' => MetaEventStatus::Failed->value, 'failure_code' => $code, 'failure_summary' => $summary]);

            MetaLog::error('job.failed', ['event' => $this->eventPublicId, 'code' => $code, 'exception' => $e::class]);
        });
    }
}
