<?php

namespace App\Tenancy;

use App\Support\Facades\Tenancy;
use App\Tenancy\Exceptions\TenantContextMissing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Tenancy::isBypassed()) {
            return;
        }

        $id = Tenancy::id();

        if ($id === null) {
            throw new TenantContextMissing(sprintf('Query on tenant-owned model [%s] without a tenant context.', $model::class));
        }

        $builder->where($model->qualifyColumn('tenant_id'), $id);
    }
}
