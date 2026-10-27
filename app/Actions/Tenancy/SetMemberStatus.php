<?php

namespace App\Actions\Tenancy;

use App\Enums\MembershipStatus;
use App\Models\TenantMembership;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetMemberStatus
{
    /** Allowed admin-driven transitions. Invitations are accepted by the invitee, not here. */
    private const TRANSITIONS = [
        'active' => ['suspended', 'removed'],
        'suspended' => ['active', 'removed'],
        'invited' => ['removed'],
        'removed' => [],
    ];

    public function __invoke(TenantMembership $membership, MembershipStatus $to): void
    {
        DB::transaction(function () use ($membership, $to): void {
            AdminGuard::lockTenant();
            $membership->refresh();

            if (! in_array($to->value, self::TRANSITIONS[$membership->status->value], true)) {
                throw ValidationException::withMessages(['status' => 'That status change is not allowed.']);
            }

            if ($to !== MembershipStatus::Active) {
                AdminGuard::ensureAnotherAdminRemains($membership);
            }

            $from = $membership->status;
            $membership->update(['status' => $to]);

            AuditLogger::record('member.status_changed', $membership, ['from' => $from->value, 'to' => $to->value]);
        });
    }
}
