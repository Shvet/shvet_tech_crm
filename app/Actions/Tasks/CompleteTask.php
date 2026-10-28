<?php

namespace App\Actions\Tasks;

use App\Enums\ActivityType;
use App\Enums\TaskStatus;
use App\Models\FollowUpTask;
use App\Models\LeadActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteTask
{
    public function complete(User $actor, FollowUpTask $task, ?string $outcome): FollowUpTask
    {
        return DB::transaction(function () use ($actor, $task, $outcome) {
            $locked = FollowUpTask::query()->lockForUpdate()->findOrFail($task->getKey());

            if (! $locked->isOpen()) {
                return $locked; // idempotent: a double click does not complete (or log) twice
            }
            if ($locked->lead->isArchived()) {
                throw ValidationException::withMessages(['task' => 'The lead is archived. Restore it first.']);
            }

            $locked->forceFill([
                'status' => TaskStatus::Completed,
                'completed_at' => now(),
                'completed_by' => $actor->getKey(),
                'outcome' => $outcome,
            ])->save();

            // Leave a trace on the lead's timeline.
            LeadActivity::query()->create([
                'lead_id' => $locked->lead_id,
                'user_id' => $actor->getKey(),
                'type' => ActivityType::Note,
                'subject' => 'Follow-up completed: '.$locked->title,
                'body' => $outcome,
                'occurred_at' => now(),
            ]);

            return $locked;
        });
    }

    public function reopen(User $actor, FollowUpTask $task): FollowUpTask
    {
        return DB::transaction(function () use ($task) {
            $locked = FollowUpTask::query()->lockForUpdate()->findOrFail($task->getKey());

            if ($locked->isOpen()) {
                return $locked;
            }
            if ($locked->lead->isArchived()) {
                throw ValidationException::withMessages(['task' => 'The lead is archived. Restore it first.']);
            }

            $locked->forceFill(['status' => TaskStatus::Open, 'completed_at' => null, 'completed_by' => null, 'outcome' => null])->save();

            return $locked;
        });
    }
}
