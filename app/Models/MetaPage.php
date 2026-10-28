<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A connected Facebook Page. The access token is encrypted at rest and hidden from serialization. */
#[Fillable([
    'meta_connection_id', 'page_id', 'name', 'access_token', 'subscription_status', 'subscription_error',
    'subscribed_at', 'last_sync_at',
])]
#[Hidden(['access_token'])]
class MetaPage extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::creating(function (MetaPage $p): void {
            $p->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'subscribed_at' => 'datetime',
            'last_sync_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MetaConnection::class, 'meta_connection_id');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(MetaFormMapping::class);
    }
}
