<?php

namespace App\Actions\Tasks;

use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Models\Lead;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Leads\LeadVisibility;
use Illuminate\Validation\ValidationException;

/**
 * Who may a follow-up be assigned to?
 *  - the assignee must be an ACTIVE member of this tenant (tenant-scoped lookup),
 *  - must hold tasks.manage and be able to SEE the lead (a task must never reveal a lead to someone who could not open it),
 *  - and only users with leads.assign may assign work to somebody else.
 */
class EnsureAssignable
{
    public static function check(User $actor, Lead $lead, int $assigneeUserId): User
    {
        $active = TenantMembership::query()->where('user_id', $assigneeUserId)
            ->where('status', MembershipStatus::Active->value)->exists();
        $assignee = $active ? User::query()->find($assigneeUserId) : null;

        if ($assignee === null) {
            throw ValidationException::withMessages(['assigned_to' => 'Choose an active member of this workspace.']);
        }

        if ($assignee->getKey() !== $actor->getKey() && ! $actor->checkPermissionTo(Permission::LeadsAssign->value)) {
            throw ValidationException::withMessages(['assigned_to' => 'You can only assign follow-ups to yourself.']);
        }

        $assignee->forgetTenantCaches();
        if (! $assignee->checkPermissionTo(Permission::TasksManage->value) || ! LeadVisibility::canView($assignee, $lead)) {
            throw ValidationException::withMessages(['assigned_to' => 'That person cannot access this lead, so they cannot be given a follow-up on it.']);
        }

        return $assignee;
    }
}
