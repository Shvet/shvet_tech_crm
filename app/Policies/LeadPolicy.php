<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\LeadVisibility;

/**
 * Tenant isolation is automatic (global scope); THIS policy is the within-tenant authorization.
 * Every write requires the permission AND visibility of the specific lead (so an Agent can only
 * act on leads they own), and most writes are refused on archived leads.
 */
class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return LeadVisibility::level($user) !== LeadVisibility::NONE;
    }

    public function view(User $user, Lead $lead): bool
    {
        return LeadVisibility::canView($user, $lead);
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo(Permission::LeadsCreate->value);
    }

    public function update(User $user, Lead $lead): bool
    {
        return $this->writable($user, $lead, Permission::LeadsUpdate);
    }

    public function changeStage(User $user, Lead $lead): bool
    {
        return $this->writable($user, $lead, Permission::LeadsUpdate);
    }

    public function logActivity(User $user, Lead $lead): bool
    {
        return $this->writable($user, $lead, Permission::LeadsUpdate);
    }

    public function createTask(User $user, Lead $lead): bool
    {
        return $this->writable($user, $lead, Permission::TasksManage);
    }

    /** Change owner. Not available to Sales Agents (no leads.assign). */
    public function assign(User $user, Lead $lead): bool
    {
        return $this->writable($user, $lead, Permission::LeadsAssign);
    }

    public function archive(User $user, Lead $lead): bool
    {
        return $user->checkPermissionTo(Permission::LeadsArchive->value) && LeadVisibility::canView($user, $lead) && ! $lead->isArchived();
    }

    public function restore(User $user, Lead $lead): bool
    {
        return $user->checkPermissionTo(Permission::LeadsArchive->value) && LeadVisibility::canView($user, $lead) && $lead->isArchived();
    }

    private function writable(User $user, Lead $lead, Permission $permission): bool
    {
        return $user->checkPermissionTo($permission->value)
            && LeadVisibility::canView($user, $lead)
            && ! $lead->isArchived();
    }
}
