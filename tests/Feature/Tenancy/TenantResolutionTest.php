<?php

namespace Tests\Feature\Tenancy;

use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Enums\TenantStatus;
use App\Http\Middleware\ResolveTenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Facades\Tenancy;
use Tests\TestCase;

class TenantResolutionTest extends TestCase
{
    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_user_with_no_membership_cannot_enter_any_workspace(): void
    {
        $this->provision('Alpha');
        $loner = User::factory()->create();

        $this->actingAs($loner)->get('/dashboard')->assertRedirect(route('workspaces.index'));
        $this->actingAs($loner)->getJson('/dashboard')->assertForbidden();
    }

    public function test_single_membership_is_selected_automatically(): void
    {
        [$a] = $this->provision('Alpha');
        $agent = $this->member($a, RoleName::SalesAgent);

        $this->actingAs($agent)->get('/dashboard')->assertOk();
        $this->assertSame($a->id, session(ResolveTenant::SESSION_KEY));
    }

    public function test_multiple_memberships_require_an_explicit_choice(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');
        $user = $this->member($a, RoleName::SalesAgent);
        $this->addMembership($b, $user, RoleName::SalesManager);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('workspaces.index'));

        $this->actingAs($user)->post(route('workspaces.select', $b))->assertRedirect(route('dashboard'));
        $this->assertSame($b->id, session(ResolveTenant::SESSION_KEY));
    }

    public function test_cannot_select_a_workspace_without_an_active_membership(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');
        $agent = $this->member($a, RoleName::SalesAgent);

        $this->actingAs($agent)->post(route('workspaces.select', $b))->assertForbidden();
        $this->assertNull(session(ResolveTenant::SESSION_KEY));
    }

    public function test_invited_suspended_and_removed_memberships_cannot_be_selected(): void
    {
        [$a] = $this->provision('Alpha');

        foreach ([MembershipStatus::Invited, MembershipStatus::Suspended, MembershipStatus::Removed] as $status) {
            $user = $this->member($a, RoleName::SalesAgent, $status);
            $this->actingAs($user)->post(route('workspaces.select', $a))->assertForbidden();
        }
    }

    public function test_session_tenant_is_revalidated_on_every_request(): void
    {
        [$a] = $this->provision('Alpha');
        $agent = $this->member($a, RoleName::SalesAgent);

        $this->actingInTenant($agent, $a)->get('/dashboard')->assertOk();

        // Membership suspended mid-session -> next request is refused.
        $this->runIn($a, fn () => TenantMembership::query()->where('user_id', $agent->id)->update(['status' => 'suspended']));
        $this->get('/dashboard')->assertRedirect(route('workspaces.index'));
    }

    public function test_suspended_tenant_blocks_all_its_members(): void
    {
        [$a] = $this->provision('Alpha');
        $agent = $this->member($a, RoleName::SalesAgent);

        $a->update(['status' => TenantStatus::Suspended]);

        $this->actingInTenant($agent, $a)->get('/dashboard')->assertRedirect(route('workspaces.index'));
    }

    public function test_deactivated_user_is_logged_out_on_next_request(): void
    {
        [$a] = $this->provision('Alpha');
        $agent = $this->member($a, RoleName::SalesAgent);

        $this->actingInTenant($agent, $a)->get('/dashboard')->assertOk();
        $agent->forceFill(['is_active' => false])->save();

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_tenant_identity_in_request_input_is_ignored(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');
        $agentA = $this->member($a, RoleName::SalesAgent);

        $this->actingInTenant($agentA, $a)
            ->withHeaders(['X-Tenant' => $b->public_id, 'X-Tenant-Id' => (string) $b->id])
            ->get('/dashboard?tenant_id='.$b->id.'&tenant='.$b->public_id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('tenant.id', $a->public_id));
    }

    public function test_forged_session_tenant_without_membership_is_rejected(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');
        $agentA = $this->member($a, RoleName::SalesAgent);

        $this->actingInTenant($agentA, $b)->get('/dashboard')->assertRedirect(route('workspaces.index'));
    }

    public function test_same_user_has_different_roles_and_permissions_per_tenant(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');
        $user = $this->member($a, RoleName::SalesAgent);
        $this->addMembership($b, $user, RoleName::Admin);

        $this->actingInTenant($user, $a)->get('/team')->assertForbidden();
        $this->actingInTenant($user, $b)->get('/team')->assertOk();
        // And back again within the same test process: no stale cached roles.
        $this->actingInTenant($user, $a)->get('/team')->assertForbidden();
    }

    public function test_request_context_is_cleared_after_the_request(): void
    {
        [$a] = $this->provision('Alpha');
        $agent = $this->member($a, RoleName::SalesAgent);

        $this->actingInTenant($agent, $a)->get('/dashboard')->assertOk();

        $this->assertNull(Tenancy::id());
    }

    public function test_workspace_picker_lists_only_own_workspaces(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');
        [$c] = $this->provision('Gamma');
        $user = $this->member($a, RoleName::SalesAgent);
        $this->addMembership($b, $user, RoleName::SalesAgent, MembershipStatus::Invited);

        $this->actingAs($user)->get('/workspaces')->assertOk()->assertInertia(fn ($page) => $page
            ->has('workspaces', 2)
            ->where('workspaces.0.id', $a->public_id)
            ->where('workspaces.1.id', $b->public_id)
            ->where('workspaces.1.invited', true));
    }
}
