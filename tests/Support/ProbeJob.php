<?php

namespace Tests\Support;

use App\Support\Facades\Tenancy;
use App\Tenancy\Queue\QueuesForTenant;
use App\Tenancy\Queue\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Test-only tenant-aware job that records the tenant it observed while running. */
class ProbeJob implements ShouldQueue, TenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable, QueuesForTenant;

    /** @var list<int|null> */
    public static array $seen = [];

    public function __construct(?int $tenantId = null, public bool $explode = false)
    {
        $this->captureTenant($tenantId);
    }

    public function handle(): void
    {
        self::$seen[] = Tenancy::id();

        if ($this->explode) {
            throw new \RuntimeException('boom');
        }
    }
}
