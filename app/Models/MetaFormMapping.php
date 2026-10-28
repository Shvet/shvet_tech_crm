<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'meta_page_id', 'form_id', 'form_name', 'form_status', 'lead_source_id', 'pipeline_stage_id',
    'default_owner_id', 'is_enabled', 'field_map', 'last_import_at',
])]
class MetaFormMapping extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::creating(function (MetaFormMapping $m): void {
            $m->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'field_map' => 'array',
            'last_import_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(MetaPage::class, 'meta_page_id');
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
