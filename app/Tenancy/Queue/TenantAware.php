<?php

namespace App\Tenancy\Queue;

/** Implemented by every queued job that reads or writes tenant-owned data. */
interface TenantAware
{
    public function tenantId(): int;
}
