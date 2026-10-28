<?php

namespace App\Actions\Tasks;

use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateTask
{
    /** @param  array<string, mixed>  $data  title, description?, priority, due_at (CarbonInterface, UTC), assigned_to */
    public function __invoke(User $actor, Lead $lead, array $data): FollowUpTask
    {
        return DB::transaction(function () use ($actor, $lead, $data) {
            $locked = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());

            if ($locked->isArchived()) {
                throw ValidationException::withMessages(['lead' => 'Archived leads cannot receive new follow-ups. Restore the lead first.']);
            }

            EnsureAssignable::check($actor, $locked, (int) $data['assigned_to']);

            return FollowUpTask::query()->create([
                'lead_id' => $locked->getKey(),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'priority' => $data['priority'] ?? 'medium',
                'due_at' => $data['due_at'],
                'assigned_to' => $data['assigned_to'],
                'created_by' => $actor->getKey(),
            ]);
        });
    }
}
