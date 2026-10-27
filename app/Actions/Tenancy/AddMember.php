<?php

namespace App\Actions\Tenancy;

use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invites a person into the CURRENT tenant. Creates the (global) user if needed, but the membership
 * starts as "invited" and NEVER grants access by itself: the invitee must be signed in as that user
 * and explicitly accept within the invitation lifetime. A new account additionally has to set its own
 * password through the e-mailed link, which proves control of the address.
 */
class AddMember
{
    /** @return array{membership: TenantMembership, sendSetupLink: bool} */
    public function __invoke(User $inviter, string $name, string $email, RoleName $role): array
    {
        $tenant = Tenancy::currentOrFail();
        $email = Str::lower(trim($email));

        return DB::transaction(function () use ($inviter, $name, $email, $role, $tenant) {
            AdminGuard::lockTenant();

            $pending = TenantMembership::query()->where('status', MembershipStatus::Invited->value)
                ->where('invitation_expires_at', '>', now())->count();

            $user = User::query()->where('email', $email)->first();
            $membership = $user ? TenantMembership::query()->where('user_id', $user->getKey())->first() : null;

            if ($membership && ! in_array($membership->status, [MembershipStatus::Removed, MembershipStatus::Invited], true)) {
                throw ValidationException::withMessages(['email' => 'This person is already a member of this workspace.']);
            }

            $isRefresh = $membership !== null && $membership->status === MembershipStatus::Invited;
            if (! $isRefresh && $pending >= (int) config('crm.max_pending_invitations')) {
                throw ValidationException::withMessages(['email' => 'Too many pending invitations. Cancel or wait for some to expire.']);
            }

            $user ??= User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::random(64)),
            ]);

            $invitation = [
                'status' => MembershipStatus::Invited,
                'invited_by' => $inviter->getKey(),
                'joined_at' => null,
                'invited_at' => now(),
                'invitation_expires_at' => now()->addDays((int) config('crm.invitation_ttl_days')),
            ];

            if ($membership) {
                $membership->update($invitation);
            } else {
                $membership = TenantMembership::query()->create([
                    'tenant_id' => $tenant->getKey(),
                    'user_id' => $user->getKey(),
                    ...$invitation,
                ]);
            }

            $user->forgetTenantCaches();
            $user->syncRoles([SyncTenantRoles::roleFor($role)]);

            AuditLogger::record($isRefresh ? 'member.invitation_renewed' : 'member.invited', $membership, ['role' => $role->value]);

            // Accounts that have never signed in cannot use the workspace picker without a password.
            return ['membership' => $membership, 'sendSetupLink' => $user->last_login_at === null];
        });
    }
}
