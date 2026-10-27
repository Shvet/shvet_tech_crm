<?php

namespace Tests;

use App\Actions\Tenancy\ProvisionTenant;
use App\Actions\Tenancy\SyncTenantRoles;
use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Facades\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Never let RefreshDatabase wipe a non-test database. */
    protected function beforeRefreshingDatabase(): void
    {
        $name = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with($name, '_test')) {
            throw new \RuntimeException("Refusing to refresh database [{$name}]: test databases must end in _test.");
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** Provision a real tenant (roles, sources, pipeline, admin). Returns [tenant, admin]. */
    protected function provision(string $name, ?string $adminEmail = null): array
    {
        $result = (new ProvisionTenant)($name, $adminEmail ?? fake()->unique()->safeEmail(), 'Admin of '.$name);

        return [$result['tenant'], $result['admin']];
    }

    /** Create a user with a membership + role in $tenant. */
    protected function member(Tenant $tenant, RoleName $role, MembershipStatus $status = MembershipStatus::Active, array $userAttributes = []): User
    {
        $user = User::factory()->create($userAttributes);

        return $this->addMembership($tenant, $user, $role, $status);
    }

    protected function addMembership(Tenant $tenant, User $user, RoleName $role, MembershipStatus $status = MembershipStatus::Active): User
    {
        Tenancy::runAs($tenant, function () use ($tenant, $user, $role, $status): void {
            TenantMembership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'status' => $status,
                'joined_at' => $status === MembershipStatus::Active ? now() : null,
                'invited_at' => $status === MembershipStatus::Invited ? now() : null,
                'invitation_expires_at' => $status === MembershipStatus::Invited ? now()->addDays(7) : null,
            ]);
            $user->forgetTenantCaches();
            $user->assignRole(SyncTenantRoles::roleFor($role));
        });

        return $user->fresh();
    }

    /** Authenticate $user and select $tenant as the active workspace (as the picker would). */
    protected function actingInTenant(User $user, Tenant $tenant): static
    {
        // Fresh session per switch: AuthenticateSession ties a session to one password hash.
        $this->flushSession();

        return $this->actingAs($user)->withSession([ResolveTenant::SESSION_KEY => $tenant->id]);
    }

    /** Drop any authenticated user (the guard keeps actingAs() users for the whole test process). */
    protected function asGuest(): static
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    protected function runIn(Tenant $tenant, \Closure $callback): mixed
    {
        return Tenancy::runAs($tenant, $callback);
    }
}
