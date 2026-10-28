<?php

namespace App\Models;

use App\Enums\Priority;
use App\Tenancy\BelongsToTenant;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Tenant-owned sales lead. Never mass-assign from request input: use the Actions in App\Actions\Leads,
 * which pick fields explicitly (owner, actor and archive columns are authorization-sensitive).
 */
#[Fillable([
    'full_name', 'phone_e164', 'email', 'company_name', 'priority', 'estimated_value',
    'lead_source_id', 'pipeline_stage_id', 'owner_id', 'created_by', 'submission_token',
    'external_provider', 'external_id', 'stage_changed_at', 'last_contacted_at', 'archived_at', 'archived_by',
])]
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

    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'estimated_value' => 'decimal:2',
            'stage_changed_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(FollowUpTask::class);
    }

    public function stageHistory(): HasMany
    {
        return $this->hasMany(LeadStageHistory::class);
    }
}
