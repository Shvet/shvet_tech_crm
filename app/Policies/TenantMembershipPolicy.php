<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\TenantMembership;
use App\Models\User;

class TenantMembershipPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo(Permission::UsersView->value);
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo(Permission::UsersManage->value);
    }

    public function update(User $user, TenantMembership $membership): bool
    {
        return $user->checkPermissionTo(Permission::UsersManage->value);
    }

    public function delete(User $user, TenantMembership $membership): bool
    {
        return $user->checkPermissionTo(Permission::UsersManage->value);
    }
}
