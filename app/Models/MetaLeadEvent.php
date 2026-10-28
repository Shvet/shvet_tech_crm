<?php

namespace App\Models;

use App\Enums\MetaEventStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One received `leadgen` webhook entry. Holds identifiers only (no lead PII); tenant_id is null while quarantined.
 * Unique on (page_id, leadgen_id) so redeliveries collapse into one row.
 */
#[Fillable([
    'tenant_id', 'meta_page_id', 'page_id', 'form_id', 'leadgen_id', 'ad_id', 'adgroup_id', 'campaign_id',
    'platform', 'is_organic', 'created_time', 'status', 'outcome', 'attempts', 'last_attempt_at', 'processed_at',
    'failure_code', 'failure_summary', 'warnings', 'correlation_id', 'lead_id', 'matched_lead_id',
    'confirm_duplicate', 'received_at',
])]
class MetaLeadEvent extends Model
{
    use BelongsToTenant;

    /** Webhook intake runs before a tenant is known. */
    public bool $allowsPlatformScope = true;

    protected static function booted(): void
    {
        static::creating(function (MetaLeadEvent $e): void {
            $e->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => MetaEventStatus::class,
            'is_organic' => 'boolean',
            'confirm_duplicate' => 'boolean',
            'warnings' => 'array',
            'created_time' => 'datetime',
            'last_attempt_at' => 'datetime',
            'processed_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
