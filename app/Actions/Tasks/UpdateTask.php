<?php

namespace App\Actions\Tasks;

use App\Models\FollowUpTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateTask
{
    /** @param  array<string, mixed>  $data */
    public function __invoke(User $actor, FollowUpTask $task, array $data): FollowUpTask
    {
        return DB::transaction(function () use ($actor, $task, $data) {
            $locked = FollowUpTask::query()->lockForUpdate()->findOrFail($task->getKey());

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['task' => 'Completed follow-ups cannot be edited. Reopen it first.']);
            }
            if ($locked->lead->isArchived()) {
                throw ValidationException::withMessages(['task' => 'The lead is archived. Restore it first.']);
            }

            if (isset($data['assigned_to']) && (int) $data['assigned_to'] !== (int) $locked->assigned_to) {
                EnsureAssignable::check($actor, $locked->lead, (int) $data['assigned_to']);
            }

            $locked->fill(array_intersect_key($data, array_flip(['title', 'description', 'priority', 'due_at', 'assigned_to'])))->save();

            return $locked;
        });
    }
}
