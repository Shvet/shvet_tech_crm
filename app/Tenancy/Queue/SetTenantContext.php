<?php

namespace App\Tenancy\Queue;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Support\Facades\Tenancy;
use App\Tenancy\Exceptions\TenantUnavailable;
use Closure;

/**
 * Job middleware: boots the tenant stored in the payload (never "whatever was current"),
 * refuses to run for a missing/suspended tenant, and always restores the previous context
 * so a long-lived worker cannot leak tenant state into the next job.
 */
class SetTenantContext
{
    public function handle(object $job, Closure $next): mixed
    {
        if (! $job instanceof TenantAware) {
            throw new \LogicException(sprintf('%s must implement %s.', $job::class, TenantAware::class));
        }

        $tenant = Tenancy::withoutScope(fn () => Tenant::query()->find($job->tenantId()));

        if (! $tenant || $tenant->status !== TenantStatus::Active) {
            $exception = new TenantUnavailable(sprintf('Tenant %d is missing or not active.', $job->tenantId()));

            if (method_exists($job, 'fail')) {
                $job->fail($exception); // permanent: do not retry for a suspended tenant

                return null;
            }

            throw $exception;
        }

        return Tenancy::runAs($tenant, fn () => $next($job));
    }
}
