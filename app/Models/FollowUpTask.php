<?php

namespace App\Models;

use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'lead_id', 'title', 'description', 'priority', 'due_at', 'assigned_to', 'created_by',
    'status', 'completed_at', 'completed_by', 'outcome',
])]
class FollowUpTask extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::creating(function (FollowUpTask $task): void {
            $task->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'status' => TaskStatus::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function isOpen(): bool
    {
        return $this->status === TaskStatus::Open;
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
