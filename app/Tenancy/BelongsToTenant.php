<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Support\Facades\Tenancy;
use App\Tenancy\Exceptions\TenantMismatch;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned: every query is scoped to the current tenant, new rows get
 * the current tenant_id, and tenant_id can never be forged or changed afterwards.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            if (Tenancy::isBypassed()) {
                if ($model->tenant_id === null && ! ($model->allowsPlatformScope ?? false)) {
                    throw new TenantMismatch('tenant_id must be set explicitly when creating outside a tenant context.');
                }

                return;
            }

            $current = Tenancy::idOrFail();

            if ($model->tenant_id === null) {
                $model->tenant_id = $current;
            } elseif ((int) $model->tenant_id !== $current) {
                throw new TenantMismatch('Refusing to create a record for a different tenant.');
            }
        });

        static::updating(function ($model): void {
            if ($model->isDirty('tenant_id')) {
                throw new TenantMismatch('tenant_id is immutable.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
