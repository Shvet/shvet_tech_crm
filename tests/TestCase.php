<?php

namespace Tests;

use App\Actions\Tenancy\ProvisionTenant;
use App\Actions\Tenancy\SyncTenantRoles;
use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Http\Middleware\ResolveTenant;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Facades\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

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

    /** Create a lead inside $tenant. $attributes may set owner_id (a user id), pipeline_stage_id, archived_at, ... */
    protected function lead(Tenant $tenant, array $attributes = [], ?string $stageKey = null): Lead
    {
        return Tenancy::runAs($tenant, function () use ($attributes, $stageKey) {
            if ($stageKey !== null) {
                $attributes['pipeline_stage_id'] = PipelineStage::query()->where('key', $stageKey)->value('id');
            }

            return Lead::factory()->create($attributes);
        });
    }

    protected function stageId(Tenant $tenant, string $key): int
    {
        return Tenancy::runAs($tenant, fn () => (int) PipelineStage::query()->where('key', $key)->value('id'));
    }

    protected function sourceId(Tenant $tenant, string $key = 'manual'): int
    {
        return Tenancy::runAs($tenant, fn () => (int) LeadSource::query()->where('key', $key)->value('id'));
    }

    /** Valid payload for POST /leads inside $tenant. */
    protected function leadPayload(Tenant $tenant, array $overrides = []): array
    {
        return [
            'full_name' => 'Asha Verma',
            'phone' => '98765 43210',
            'email' => 'asha@example.test',
            'company_name' => 'Verma Traders',
            'priority' => 'high',
            'estimated_value' => '125000.50',
            'lead_source_id' => $this->sourceId($tenant),
            'submission_token' => (string) Str::uuid(),
            ...$overrides,
        ];
    }

    protected function runIn(Tenant $tenant, \Closure $callback): mixed
    {
        return Tenancy::runAs($tenant, $callback);
    }
}
