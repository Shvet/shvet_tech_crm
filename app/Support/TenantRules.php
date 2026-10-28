<?php

namespace App\Support;

use App\Enums\MembershipStatus;
use App\Support\Facades\Tenancy;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/** Validation rules that can only ever match rows of the CURRENT tenant. */
class TenantRules
{
    public static function exists(string $table, string $column = 'id', bool $activeOnly = false): Exists
    {
        $rule = Rule::exists($table, $column)->where('tenant_id', Tenancy::idOrFail());

        return $activeOnly ? $rule->where('is_active', true) : $rule;
    }

    /** A user id that is an ACTIVE member of the current tenant. */
    public static function activeMember(): Exists
    {
        return Rule::exists('tenant_memberships', 'user_id')
            ->where('tenant_id', Tenancy::idOrFail())
            ->where('status', MembershipStatus::Active->value);
    }
}
