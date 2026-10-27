<?php

namespace App\Actions\Tenancy;

use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Models\TenantMembership;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeMemberRole
{
    public function __invoke(TenantMembership $membership, RoleName $role): void
    {
        DB::transaction(function () use ($membership, $role): void {
            AdminGuard::lockTenant();
            $membership->refresh(); // state may have changed since route binding; decide under the lock

            if ($membership->status === MembershipStatus::Removed) {
                throw ValidationException::withMessages(['member' => 'Removed members cannot be changed. Invite them again first.']);
            }

            if ($role !== RoleName::Admin) {
                AdminGuard::ensureAnotherAdminRemains($membership);
            }

            $user = $membership->user;
            $user->forgetTenantCaches();
            $previous = $user->roles->pluck('name')->all();
            $user->syncRoles([SyncTenantRoles::roleFor($role)]);

            AuditLogger::record('member.role_changed', $membership, ['from' => $previous, 'to' => $role->value]);
        });
    }
}
