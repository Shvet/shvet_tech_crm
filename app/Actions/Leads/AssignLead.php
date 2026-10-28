<?php

namespace App\Actions\Leads;

use App\Enums\TaskStatus;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\ActiveMemberGuard;
use App\Services\Leads\LeadVisibility;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes (or clears) a lead's owner. The new owner must be an ACTIVE member of this tenant. Open tasks
 * whose assignee could no longer see the lead afterwards (an agent who just lost ownership) move to
 * the new owner, or to the person doing the unassignment, so nobody keeps tasks for a lead they cannot open.
 */
class AssignLead
{
    public function __invoke(User $actor, Lead $lead, ?int $newOwnerUserId): void
    {
        DB::transaction(function () use ($actor, $lead, $newOwnerUserId) {
            $locked = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());

            if ($locked->isArchived()) {
                throw ValidationException::withMessages(['lead' => 'Archived leads cannot be reassigned. Restore the lead first.']);
            }

            if ($newOwnerUserId !== null) {
                ActiveMemberGuard::ensure($newOwnerUserId, 'owner_id');
            }

            $previous = $locked->owner_id;
            if ($previous === $newOwnerUserId) {
                return;
            }

            $locked->forceFill(['owner_id' => $newOwnerUserId])->save();

            $fallback = $newOwnerUserId ?? $actor->getKey();
            FollowUpTask::query()->where('lead_id', $locked->getKey())->where('status', TaskStatus::Open->value)
                ->get()
                ->each(function (FollowUpTask $task) use ($locked, $fallback) {
                    $assignee = User::query()->find($task->assigned_to);
                    if ($assignee && ! $this->canStillSee($assignee, $locked)) {
                        $task->forceFill(['assigned_to' => $fallback])->save();
                    }
                });

            AuditLogger::record('lead.assigned', $locked, ['from_user_id' => $previous, 'to_user_id' => $newOwnerUserId]);

            $lead->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function canStillSee(User $user, Lead $lead): bool
    {
        $user->forgetTenantCaches();

        return LeadVisibility::canView($user, $lead);
    }
}
