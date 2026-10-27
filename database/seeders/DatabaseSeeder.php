<?php

namespace Database\Seeders;

use App\Actions\Tenancy\ProvisionTenant;
use App\Actions\Tenancy\SyncTenantRoles;
use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Facades\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Development/demo data ONLY. Refuses to run outside local/testing: production tenants are created
 * with `php artisan tenants:provision`. The shared demo password is public knowledge.
 */
class DatabaseSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'Demo-Password-123';

    public function run(): void
    {
        // Allow-list, not "not production": a staging host with a public URL must never get known-password accounts.
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demo seeding only runs in local/testing environments. Use tenants:provision.');
        }

        $provision = new ProvisionTenant;
        $shvet = $provision('Shvet Technologies', 'admin@shvet.test', 'Shvet Admin', 'shvet')['tenant'];
        $acme = $provision('Acme Demo Co', 'admin@acme.test', 'Acme Admin', 'acme')['tenant'];

        User::query()->whereIn('email', ['admin@shvet.test', 'admin@acme.test'])
            ->each(fn (User $u) => $u->forceFill(['password' => Hash::make(self::DEMO_PASSWORD), 'last_login_at' => now()])->save());

        $this->addUser($shvet, 'manager@shvet.test', 'Shvet Manager', RoleName::SalesManager);
        $this->addUser($shvet, 'agent@shvet.test', 'Shvet Agent', RoleName::SalesAgent);
        $this->addUser($acme, 'agent@acme.test', 'Acme Agent', RoleName::SalesAgent);

        // One person who belongs to both tenants with different roles.
        $multi = $this->addUser($shvet, 'multi@demo.test', 'Multi Workspace', RoleName::SalesAgent);
        $this->attach($acme, $multi, RoleName::Admin);
    }

    private function addUser(Tenant $tenant, string $email, string $name, RoleName $role): User
    {
        $user = User::query()->forceCreate([
            'name' => $name, 'email' => $email, 'password' => Hash::make(self::DEMO_PASSWORD),
            'is_active' => true, 'last_login_at' => now(),
        ]);

        return $this->attach($tenant, $user, $role);
    }

    private function attach(Tenant $tenant, User $user, RoleName $role): User
    {
        Tenancy::runAs($tenant, function () use ($tenant, $user, $role): void {
            TenantMembership::query()->create([
                'tenant_id' => $tenant->id, 'user_id' => $user->id,
                'status' => MembershipStatus::Active, 'joined_at' => now(),
            ]);
            $user->forgetTenantCaches();
            $user->assignRole(SyncTenantRoles::roleFor($role));
        });

        return $user;
    }
}
