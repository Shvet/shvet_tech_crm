<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Append-only. tenant_id may be null for platform-level events, which tenants cannot see. */
class AuditLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /** Platform-level events (no tenant) are the only legitimate NULL tenant_id rows. */
    public bool $allowsPlatformScope = true;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit logs are append-only.'));
        static::deleting(fn () => throw new \LogicException('Audit logs are append-only.'));
    }

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }
}
