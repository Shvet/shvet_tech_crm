<?php

namespace App\Actions\Tenancy;

use App\Enums\MembershipStatus;
use App\Models\TenantMembership;
use App\Support\AuditLogger;
use Illuminate\Validation\ValidationException;

class AcceptInvitation
{
    /** Must be called inside the membership's tenant context (Tenancy::runAs). */
    public function __invoke(TenantMembership $membership): void
    {
        if ($membership->status !== MembershipStatus::Invited) {
            return;
        }

        if ($membership->invitation_expires_at === null || $membership->invitation_expires_at->isPast()) {
            throw ValidationException::withMessages(['invitation' => 'This invitation has expired. Ask an administrator to invite you again.']);
        }

        $membership->update(['status' => MembershipStatus::Active, 'joined_at' => now()]);

        AuditLogger::record('member.invitation_accepted', $membership);
    }
}
