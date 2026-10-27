<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Tenancy\ProvisionTenant;
use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\LeadSource;
use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Facades\Tenancy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProvisioningTest extends TestCase
{
    public function test_provisioning_creates_roles_defaults_and_active_admin_membership(): void
    {
        [$tenant, $admin] = $this->provision('Shvet Technologies', 'owner@shvet.test');

        $this->assertSame('shvet-technologies', $tenant->slug);
        $this->assertSame(26, strlen($tenant->public_id));

        $this->runIn($tenant, function () use ($admin) {
            $this->assertSame(
                [RoleName::Admin->value, RoleName::SalesAgent->value, RoleName::SalesManager->value],
                Role::query()->orderBy('name')->pluck('name')->all(),
            );
            $this->assertSame(7, LeadSource::query()->count());
            $this->assertSame(
                ['New', 'Contacted', 'Qualified', 'Proposal Sent', 'Negotiation', 'Won', 'Lost'],
                PipelineStage::query()->orderBy('position')->pluck('name')->all(),
            );

            $membership = TenantMembership::query()->where('user_id', $admin->id)->first();
            $this->assertSame(MembershipStatus::Active, $membership->status);
            $this->assertTrue($admin->fresh()->hasRole(RoleName::Admin->value));
            $this->assertTrue($admin->fresh()->checkPermissionTo(Permission::UsersManage->value));
            $this->assertSame(1, AuditLog::query()->where('action', 'tenant.provisioned')->count());
        });
    }

    public function test_role_permission_sets_match_the_matrix(): void
    {
        [$tenant] = $this->provision('Alpha');
        $agent = $this->member($tenant, RoleName::SalesAgent);
        $manager = $this->member($tenant, RoleName::SalesManager);

        $this->runIn($tenant, function () use ($agent, $manager) {
            $this->assertTrue($agent->checkPermissionTo(Permission::LeadsViewOwn->value));
            $this->assertFalse($agent->checkPermissionTo(Permission::LeadsViewAll->value));
            $this->assertFalse($agent->checkPermissionTo(Permission::UsersView->value));
            $this->assertFalse($agent->checkPermissionTo(Permission::IntegrationsManage->value));
            $this->assertTrue($manager->checkPermissionTo(Permission::UsersView->value));
            $this->assertFalse($manager->checkPermissionTo(Permission::UsersManage->value));
            $this->assertFalse($manager->checkPermissionTo(Permission::AuditView->value));
        });
    }

    public function test_each_tenant_has_its_own_roles(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');

        $idsA = $this->runIn($a, fn () => Role::query()->where('tenant_id', $a->id)->pluck('id')->all());
        $idsB = Tenancy::withoutScope(fn () => Role::query()->where('tenant_id', $b->id)->pluck('id')->all());

        $this->assertCount(3, $idsA);
        $this->assertSame([], array_intersect($idsA, $idsB));
    }

    public function test_existing_user_can_be_attached_as_admin_of_a_new_tenant_without_changing_password(): void
    {
        $existing = User::factory()->create(['email' => 'multi@example.test']);
        $hash = $existing->password;

        $result = (new ProvisionTenant)('Gamma', 'MULTI@example.test', 'Ignored Name');

        $this->assertFalse($result['adminIsNew']);
        $this->assertSame($existing->id, $result['admin']->id);
        $this->assertSame($hash, $existing->fresh()->password);
    }

    public function test_duplicate_slug_is_rejected_and_leaves_no_partial_data(): void
    {
        $this->provision('Alpha');
        $before = Tenant::query()->count();

        try {
            (new ProvisionTenant)('Another', 'x@example.test', 'X', 'alpha');
            $this->fail('Expected validation failure');
        } catch (ValidationException) {
        }

        $this->assertSame($before, Tenant::query()->count());
        $this->assertNull(User::query()->where('email', 'x@example.test')->first());
    }

    public function test_provisioning_is_atomic_when_a_step_fails(): void
    {
        $this->provision('Alpha');

        try {
            // Invalid e-mail aborts before anything is written.
            (new ProvisionTenant)('Delta', 'not-an-email', 'X');
        } catch (ValidationException) {
        }

        $this->assertNull(Tenant::query()->where('slug', 'delta')->first());
    }

    public function test_artisan_command_provisions_a_tenant_and_sends_setup_link(): void
    {
        Notification::fake();

        $this->artisan('tenants:provision', ['name' => 'Cmd Co', 'admin_email' => 'boss@cmd.test', '--admin-name' => 'Boss'])
            ->expectsOutputToContain('provisioned')
            ->assertSuccessful();

        $this->assertNotNull(Tenant::query()->where('slug', 'cmd-co')->first());
        Notification::assertSentTo(User::query()->where('email', 'boss@cmd.test')->first(), ResetPassword::class);
    }

    public function test_artisan_command_reports_duplicates(): void
    {
        $this->provision('Alpha');

        $this->artisan('tenants:provision', ['name' => 'Alpha', 'admin_email' => 'z@z.test'])->assertFailed();
    }
}
