<?php

namespace App\Http\Resources;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/** @mixin Lead */
class LeadResource extends JsonResource
{
    public function __construct($resource, private readonly bool $withAbilities = false)
    {
        parent::__construct($resource);
    }

    /** Explicit allow-list: nothing is exposed that is not named here. */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'full_name' => $this->full_name,
            'phone' => $this->phone_e164,
            'email' => $this->email,
            'company_name' => $this->company_name,
            'priority' => $this->priority->value,
            'estimated_value' => $this->estimated_value,
            'source' => $this->whenLoaded('source', fn () => $this->source ? ['id' => $this->source->id, 'name' => $this->source->name, 'key' => $this->source->key] : null),
            'stage' => $this->whenLoaded('stage', fn () => $this->stage ? [
                'id' => $this->stage->id, 'name' => $this->stage->name, 'key' => $this->stage->key,
                'outcome' => $this->stage->outcome, 'color' => $this->stage->color,
            ] : null),
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? ['id' => $this->owner->id, 'name' => $this->owner->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'stage_changed_at' => $this->stage_changed_at?->toIso8601String(),
            'last_contacted_at' => $this->last_contacted_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'can' => $this->when($this->withAbilities, fn () => [
                'update' => Gate::allows('update', $this->resource),
                'assign' => Gate::allows('assign', $this->resource),
                'change_stage' => Gate::allows('changeStage', $this->resource),
                'log_activity' => Gate::allows('logActivity', $this->resource),
                'create_task' => Gate::allows('createTask', $this->resource),
                'archive' => Gate::allows('archive', $this->resource),
                'restore' => Gate::allows('restore', $this->resource),
            ]),
        ];
    }
}
