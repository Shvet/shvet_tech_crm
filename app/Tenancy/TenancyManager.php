<?php

namespace App\Tenancy;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Tenancy\Exceptions\TenantContextMissing;
use Closure;
use Spatie\Permission\PermissionRegistrar;

/**
 * Holds the "current tenant" for the running request / job / command.
 *
 * Tenant-owned queries FAIL CLOSED: with no tenant set and no explicit bypass,
 * TenantScope throws. The two sanctioned ways to cross the boundary are
 * runAs() (act inside one named tenant) and withoutScope() (explicit, greppable).
 */
class TenancyManager
{
    private ?Tenant $tenant = null;

    private int $bypassDepth = 0;

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    public function currentOrFail(): Tenant
    {
        return $this->tenant ?? throw new TenantContextMissing;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function idOrFail(): int
    {
        return $this->currentOrFail()->getKey();
    }

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        // Roles are tenant-scoped (spatie "teams" mode keyed by tenant_id).
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant?->getKey());
    }

    public function clear(): void
    {
        $this->set(null);
    }

    /** Run $callback inside $tenant, then restore whatever context was active before. */
    public function runAs(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $this->set($previous);
        }
    }

    /** Explicitly leave tenant scoping. Only for provisioning, routing and fan-out code. */
    public function withoutScope(Closure $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }

    /** Scheduler fan-out: run $callback once per active tenant; one failure does not stop the rest. */
    public function eachActiveTenant(Closure $callback): void
    {
        $tenants = $this->withoutScope(fn () => Tenant::query()
            ->where('status', TenantStatus::Active->value)->orderBy('id')->get());

        foreach ($tenants as $tenant) {
            try {
                $this->runAs($tenant, fn () => $callback($tenant));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
