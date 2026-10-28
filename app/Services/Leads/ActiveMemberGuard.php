<?php

namespace App\Services\Leads;

use App\Enums\MembershipStatus;
use App\Models\TenantMembership;
use Illuminate\Validation\ValidationException;

/**
 * Action-level guarantee that a user id names an ACTIVE member of the current tenant. Form requests validate this
 * too, but actions are also reached from other callers (imports, integrations) that never pass a form request.
 */
class ActiveMemberGuard
{
    public static function ensure(int $userId, string $field): void
    {
        $active = TenantMembership::query()->where('user_id', $userId)
            ->where('status', MembershipStatus::Active->value)->exists();

        if (! $active) {
            throw ValidationException::withMessages([$field => 'Choose an active member of this workspace.']);
        }
    }
}
