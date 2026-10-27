<?php

namespace App\Tenancy\Queue;

use App\Support\Facades\Tenancy;

/**
 * Use in jobs implementing TenantAware. Call captureTenant() in the constructor.
 * Dispatching without a tenant context throws, so a job can never run "tenant-less".
 */
trait QueuesForTenant
{
    public int $tenantId;

    protected function captureTenant(?int $tenantId = null): void
    {
        $this->tenantId = $tenantId ?? Tenancy::idOrFail();
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetTenantContext];
    }
}
