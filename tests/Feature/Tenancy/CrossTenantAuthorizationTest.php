<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Tenancy\ChangeMemberRole;
use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\TenantMembership;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** IDOR matrix: a user of tenant A must never read or change anything belonging to tenant B. */
class CrossTenantAuthorizationTest extends TestCase
{
    public function test_admin_of_one_tenant_cannot_see_or_touch_another_tenants_members(): void
    {
        [$a, $adminA] = $this->provision('Alpha');
        [$b, $adminB] = $this->provision('Beta');
        $agentB = $this->member($b, RoleName::SalesAgent);
        $membershipB = $this->runIn($b, fn () => TenantMembership::query()->where('user_id', $agentB->id)->first());

        $this->actingInTenant($adminA, $a)->get('/team')->assertOk()->assertInertia(fn ($page) => $page
            ->has('members', 1)
            ->where('members.0.email', $adminA->email));

        $this->actingInTenant($adminA, $a)->patchJson("/team/members/{$membershipB->id}", ['role' => 'Admin'])->assertNotFound();
        $this->actingInTenant($adminA, $a)->patchJson("/team/members/{$membershipB->id}", ['status' => 'suspended'])->assertNotFound();
        $this->actingInTenant($adminA, $a)->deleteJson("/team/members/{$membershipB->id}")->assertNotFound();

        $this->assertSame('active', $this->runIn($b, fn () => TenantMembership::query()->find($membershipB->id))->status->value);
        $this->assertTrue($agentB->fresh()->hasRole('Sales Agent') || $this->runIn($b, fn () => $agentB->fresh()->hasRole('Sales Agent')));
    }

    public function test_tenant_admin_cannot_manage_anyone_by_forging_a_session_tenant(): void
    {
        [$a, $adminA] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');

        $this->actingInTenant($adminA, $b)->post('/team/members', [
            'name' => 'Evil', 'email' => 'evil@example.test', 'role' => 'Admin',
        ])->assertRedirect(route('workspaces.index'));

        $this->assertDatabaseMissing('users', ['email' => 'evil@example.test']);
    }

    public function test_audit_logs_are_tenant_scoped(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');

        $a_logs = $this->runIn($a, fn () => AuditLog::query()->pluck('tenant_id')->unique()->all());
        $b_logs = $this->runIn($b, fn () => AuditLog::query()->pluck('tenant_id')->unique()->all());

        $this->assertSame([$a->id], $a_logs);
        $this->assertSame([$b->id], $b_logs);
    }

    public function test_role_gates_inside_a_tenant(): void
    {
        [$a, $admin] = $this->provision('Alpha');
        $manager = $this->member($a, RoleName::SalesManager);
        $agent = $this->member($a, RoleName::SalesAgent);

        $this->actingInTenant($admin, $a)->get('/team')->assertOk();
        $this->actingInTenant($manager, $a)->get('/team')->assertOk(); // may view
        $this->actingInTenant($agent, $a)->get('/team')->assertForbidden();

        $payload = ['name' => 'N', 'email' => 'n@example.test', 'role' => 'Sales Agent'];
        $this->actingInTenant($manager, $a)->postJson('/team/members', $payload)->assertForbidden();
        $this->actingInTenant($agent, $a)->postJson('/team/members', $payload)->assertForbidden();
        $this->actingInTenant($admin, $a)->postJson('/team/members', $payload)->assertRedirect();
    }

    public function test_non_admin_cannot_change_roles_or_status(): void
    {
        [$a] = $this->provision('Alpha');
        $manager = $this->member($a, RoleName::SalesManager);
        $agent = $this->member($a, RoleName::SalesAgent);
        $target = $this->runIn($a, fn () => TenantMembership::query()->where('user_id', $agent->id)->first());

        $this->actingInTenant($manager, $a)->patchJson("/team/members/{$target->id}", ['role' => 'Admin'])->assertForbidden();
        $this->actingInTenant($agent, $a)->patchJson("/team/members/{$target->id}", ['role' => 'Admin'])->assertForbidden();
        $this->actingInTenant($agent, $a)->deleteJson("/team/members/{$target->id}")->assertForbidden();
    }

    public function test_guest_cannot_reach_tenant_routes(): void
    {
        $this->getJson('/team')->assertUnauthorized();
        $this->postJson('/team/members', [])->assertUnauthorized();
    }

    public function test_a_multi_workspace_person_is_listed_with_the_role_of_the_current_workspace_only(): void
    {
        [$a, $adminA] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');
        $multi = $this->member($a, RoleName::SalesAgent);
        $this->addMembership($b, $multi, RoleName::Admin);

        $this->actingInTenant($adminA, $a)->get('/team')->assertInertia(fn ($page) => $page
            ->has('members', 2)
            ->where('members.1.email', $multi->email)
            ->where('members.1.role', 'Sales Agent'));
    }

    public function test_stale_membership_state_cannot_be_used_to_remove_the_last_admin(): void
    {
        [$a, $adminA] = $this->provision('Alpha');
        $second = $this->member($a, RoleName::Admin, MembershipStatus::Suspended);
        $stale = $this->runIn($a, fn () => TenantMembership::query()->where('user_id', $second->id)->first()); // loaded as suspended

        // Meanwhile: second is reinstated and the first admin is suspended -> second is the ONLY active admin.
        $this->runIn($a, function () use ($second, $adminA) {
            TenantMembership::query()->where('user_id', $second->id)->update(['status' => 'active']);
            TenantMembership::query()->where('user_id', $adminA->id)->update(['status' => 'suspended']);
        });

        $this->expectException(ValidationException::class);
        $this->runIn($a, fn () => (new ChangeMemberRole)($stale, RoleName::SalesAgent));
    }

    public function test_removed_members_cannot_have_their_role_changed(): void
    {
        [$a, $admin] = $this->provision('Alpha');
        $gone = $this->member($a, RoleName::SalesAgent, MembershipStatus::Removed);
        $m = $this->runIn($a, fn () => TenantMembership::query()->where('user_id', $gone->id)->first());

        $this->actingInTenant($admin, $a)->patch("/team/members/{$m->id}", ['role' => 'Admin'])->assertSessionHasErrors('member');
    }
}
