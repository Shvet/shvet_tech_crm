<?php

namespace App\Actions\Tenancy;

use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Facades\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Guarantees a tenant never loses its last active Admin. Call inside a DB transaction. */
class AdminGuard
{
    public static function lockTenant(): void
    {
        Tenant::query()->whereKey(Tenancy::idOrFail())->lockForUpdate()->first();
    }

    public static function isActiveAdmin(TenantMembership $membership): bool
    {
        return $membership->isActive() && self::adminUserIds()->contains($membership->user_id);
    }

    public static function ensureAnotherAdminRemains(TenantMembership $leaving): void
    {
        if (! self::isActiveAdmin($leaving)) {
            return;
        }

        $others = TenantMembership::query()
            ->where('status', MembershipStatus::Active->value)
            ->whereIn('user_id', self::adminUserIds())
            ->whereKeyNot($leaving->getKey())
            ->count();

        if ($others === 0) {
            throw ValidationException::withMessages(['member' => 'A workspace must keep at least one active Admin.']);
        }
    }

    /** @return Collection<int, int> */
    private static function adminUserIds()
    {
        $roleId = SyncTenantRoles::roleFor(RoleName::Admin)->getKey();

        return DB::table(config('permission.table_names.model_has_roles'))
            ->where('tenant_id', Tenancy::idOrFail())
            ->where('role_id', $roleId)
            ->where('model_type', (new User)->getMorphClass())
            ->pluck('model_id');
    }
}
