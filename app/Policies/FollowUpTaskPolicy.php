<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\FollowUpTask;
use App\Models\User;
use App\Services\Leads\LeadVisibility;

/**
 * A task is visible exactly when its lead is visible. It may be changed by its assignee, its creator,
 * or anyone who may assign leads (managers/admins) — and never once the lead is archived.
 */
class FollowUpTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo(Permission::TasksManage->value)
            && LeadVisibility::level($user) !== LeadVisibility::NONE;
    }

    public function view(User $user, FollowUpTask $task): bool
    {
        return $user->checkPermissionTo(Permission::TasksManage->value)
            && LeadVisibility::canView($user, $task->lead);
    }

    public function update(User $user, FollowUpTask $task): bool
    {
        return $this->mayChange($user, $task);
    }

    public function complete(User $user, FollowUpTask $task): bool
    {
        return $this->mayChange($user, $task);
    }

    private function mayChange(User $user, FollowUpTask $task): bool
    {
        return $this->view($user, $task)
            && ! $task->lead->isArchived()
            && ((int) $task->assigned_to === (int) $user->getKey()
                || (int) $task->created_by === (int) $user->getKey()
                || $user->checkPermissionTo(Permission::LeadsAssign->value));
    }
}
