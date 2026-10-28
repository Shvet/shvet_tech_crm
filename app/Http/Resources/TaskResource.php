<?php

namespace App\Http\Resources;

use App\Models\FollowUpTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/** @mixin FollowUpTask */
class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'due_at' => $this->due_at->toIso8601String(),
            'is_overdue' => $this->isOpen() && $this->due_at->isPast(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'outcome' => $this->outcome,
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null),
            'lead' => $this->whenLoaded('lead', fn () => ['id' => $this->lead->public_id, 'name' => $this->lead->full_name]),
            'can' => [
                'update' => Gate::allows('update', $this->resource),
                'complete' => Gate::allows('complete', $this->resource),
            ],
        ];
    }
}
