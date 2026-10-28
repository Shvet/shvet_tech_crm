<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only record of every stage placement (model guard here, triggers in the database). */
#[Fillable(['lead_id', 'from_stage_id', 'to_stage_id', 'changed_by', 'changed_at'])]
class LeadStageHistory extends Model
{
    use BelongsToTenant;

    protected $table = 'lead_stage_history';

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Stage history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Stage history is append-only.'));
    }

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'to_stage_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
