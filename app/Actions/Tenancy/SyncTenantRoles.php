<?php

namespace App\Actions\Tenancy;

use App\Enums\Permission;
use App\Enums\RoleName;
use App\Models\Tenant;
use App\Support\Facades\Tenancy;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Idempotent: creates the three standard roles for a tenant and aligns their permissions. */
class SyncTenantRoles
{
    public function __invoke(Tenant $tenant): void
    {
        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, 'web');
        }

        Tenancy::runAs($tenant, function () use ($tenant): void {
            foreach (RoleName::cases() as $roleName) {
                $role = Role::query()->firstOrCreate([
                    'tenant_id' => $tenant->getKey(),
                    'name' => $roleName->value,
                    'guard_name' => 'web',
                ]);

                $role->syncPermissions(array_map(fn (Permission $p) => $p->value, $roleName->permissions()));
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public static function roleFor(RoleName $name): Role
    {
        return Role::query()
            ->where('tenant_id', Tenancy::idOrFail())
            ->where('name', $name->value)
            ->where('guard_name', 'web')
            ->firstOrFail();
    }
}
