<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A customer organisation. Deliberately NOT tenant-scoped itself: it is the root of the boundary.
 * Never expose the numeric id to clients; use public_id.
 */
#[Fillable(['name', 'slug', 'status', 'timezone', 'currency', 'settings'])]
class Tenant extends Model
{
    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            $tenant->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'settings' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }
}
