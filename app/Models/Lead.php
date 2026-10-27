<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** Phase 1 skeleton: the tenant-owned aggregate that proves isolation. CRUD arrives in a later slice. */
#[Fillable(['full_name', 'phone_e164', 'email', 'lead_source_id', 'pipeline_stage_id', 'owner_id'])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Lead $lead): void {
            $lead->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id');
    }
}
