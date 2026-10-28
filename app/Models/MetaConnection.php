<?php

namespace App\Models;

use App\Enums\MetaConnectionStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'created_by', 'meta_user_id', 'meta_user_name', 'status', 'granted_permissions', 'token_expires_at',
    'data_access_expires_at', 'last_validated_at', 'last_sync_at', 'last_error_code', 'last_error_summary',
    'pending_assets', 'pending_assets_expires_at', 'disconnected_at',
])]
#[Hidden(['pending_assets'])]
class MetaConnection extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::creating(function (MetaConnection $c): void {
            $c->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => MetaConnectionStatus::class,
            'granted_permissions' => 'array',
            'pending_assets' => 'encrypted:array',
            'token_expires_at' => 'datetime',
            'data_access_expires_at' => 'datetime',
            'last_validated_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'pending_assets_expires_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function pages(): HasMany
    {
        return $this->hasMany(MetaPage::class);
    }
}
