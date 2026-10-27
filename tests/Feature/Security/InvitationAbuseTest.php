<?php

namespace Tests\Feature\Security;

use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvitationAbuseTest extends TestCase
{
    private function membershipOf($tenant, User $user): ?TenantMembership
    {
        return $this->runIn($tenant, fn () => TenantMembership::query()->where('user_id', $user->id)->first());
    }

    public function test_invitations_expire_and_cannot_be_accepted_afterwards(): void
    {
        [$a, $admin] = $this->provision('Alpha');
        $invitee = $this->member($a, RoleName::SalesAgent, MembershipStatus::Invited);

        $this->travel(8)->days();

        $this->actingAs($invitee)->get('/workspaces')->assertInertia(fn ($p) => $p->has('workspaces', 0));
        $this->actingAs($invitee)->post(route('workspaces.accept', $a))->assertSessionHasErrors('invitation');
        $this->assertSame(MembershipStatus::Invited, $this->membershipOf($a, $invitee)->status);
    }

    public function test_reinviting_renews_an_expired_invitation_without_duplicating_it(): void
    {
        Notification::fake();
        [$a, $admin] = $this->provision('Alpha');
        $invitee = $this->member($a, RoleName::SalesAgent, MembershipStatus::Invited);
        $this->travel(8)->days();

        $this->actingInTenant($admin, $a)->post('/team/members', ['name' => 'x', 'email' => $invitee->email, 'role' => 'Sales Manager'])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->runIn($a, fn () => TenantMembership::query()->where('user_id', $invitee->id)->count()));
        $this->asGuest();
        $this->actingAs($invitee->fresh())->post(route('workspaces.accept', $a))->assertSessionHasNoErrors();
        $this->assertSame(MembershipStatus::Active, $this->membershipOf($a, $invitee)->status);
        $this->assertTrue($this->runIn($a, fn () => $invitee->fresh()->hasRole('Sales Manager')));
        $this->assertSame(1, $this->runIn($a, fn () => AuditLog::query()->where('action', 'member.invitation_renewed')->count()));
    }

    public function test_only_the_invited_user_can_accept_and_only_for_their_own_membership(): void
    {
        [$a] = $this->provision('Alpha');
        $invitee = $this->member($a, RoleName::SalesAgent, MembershipStatus::Invited);
        [$b] = $this->provision('Beta');
        $bystander = $this->member($b, RoleName::SalesAgent);

        $this->actingAs($bystander)->post(route('workspaces.accept', $a))->assertForbidden();
        $this->assertSame(MembershipStatus::Invited, $this->membershipOf($a, $invitee)->status);
    }

    public function test_suspended_and_removed_memberships_cannot_be_revived_by_self_acceptance(): void
    {
        [$a] = $this->provision('Alpha');
        foreach ([MembershipStatus::Suspended, MembershipStatus::Removed] as $status) {
            $user = $this->member($a, RoleName::SalesAgent, $status);
            $this->actingAs($user)->post(route('workspaces.accept', $a))->assertForbidden();
            $this->assertSame($status, $this->membershipOf($a, $user)->status);
        }
    }

    public function test_the_inviting_tenant_cannot_renew_or_inspect_another_tenants_invitation(): void
    {
        Notification::fake();
        [$a] = $this->provision('Alpha');
        [$b, $adminB] = $this->provision('Beta');
        $invitee = $this->member($a, RoleName::SalesAgent, MembershipStatus::Invited);

        // Beta inviting the same address creates Beta's own, separate membership; Alpha's is untouched.
        $this->actingInTenant($adminB, $b)->post('/team/members', ['name' => 'x', 'email' => $invitee->email, 'role' => 'Sales Agent'])->assertSessionHasNoErrors();

        $this->assertSame(MembershipStatus::Invited, $this->membershipOf($b, $invitee)->status);
        $this->assertNotSame($this->membershipOf($a, $invitee)->id, $this->membershipOf($b, $invitee)->id);
    }

    public function test_pending_invitations_are_capped_per_workspace(): void
    {
        Notification::fake();
        config(['crm.max_pending_invitations' => 3, 'crm.invitations_per_hour' => 1000]);
        [$a, $admin] = $this->provision('Alpha');

        foreach (range(1, 3) as $i) {
            $this->actingInTenant($admin, $a)->post('/team/members', ['name' => "n$i", 'email' => "n$i@example.test", 'role' => 'Sales Agent'])->assertSessionHasNoErrors();
        }
        $this->actingInTenant($admin, $a)->post('/team/members', ['name' => 'n4', 'email' => 'n4@example.test', 'role' => 'Sales Agent'])->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'n4@example.test']);

        // Renewing an existing pending invitation is still allowed at the cap.
        $this->actingInTenant($admin, $a)->post('/team/members', ['name' => 'n1', 'email' => 'n1@example.test', 'role' => 'Sales Agent'])->assertSessionHasNoErrors();
    }

    public function test_expired_invitations_do_not_count_towards_the_cap(): void
    {
        Notification::fake();
        config(['crm.max_pending_invitations' => 1, 'crm.invitations_per_hour' => 1000]);
        [$a, $admin] = $this->provision('Alpha');
        $this->actingInTenant($admin, $a)->post('/team/members', ['name' => 'n1', 'email' => 'n1@example.test', 'role' => 'Sales Agent']);
        $this->travel(8)->days();

        $this->actingInTenant($admin, $a)->post('/team/members', ['name' => 'n2', 'email' => 'n2@example.test', 'role' => 'Sales Agent'])->assertSessionHasNoErrors();
    }

    public function test_invitation_sending_is_rate_limited_per_inviter(): void
    {
        Notification::fake();
        config(['crm.invitations_per_hour' => 3, 'crm.max_pending_invitations' => 1000]);
        [$a, $admin] = $this->provision('Alpha');

        foreach (range(1, 3) as $i) {
            $this->actingInTenant($admin, $a)->post('/team/members', ['name' => "n$i", 'email' => "n$i@example.test", 'role' => 'Sales Agent'])->assertSessionHasNoErrors();
        }

        $this->actingInTenant($admin, $a)->postJson('/team/members', ['name' => 'n4', 'email' => 'n4@example.test', 'role' => 'Sales Agent'])->assertStatus(429);
        $this->assertDatabaseMissing('users', ['email' => 'n4@example.test']);
    }

    public function test_existing_active_or_suspended_members_cannot_be_re_invited(): void
    {
        [$a, $admin] = $this->provision('Alpha');
        $suspended = $this->member($a, RoleName::SalesAgent, MembershipStatus::Suspended);

        $this->actingInTenant($admin, $a)->post('/team/members', ['name' => 'x', 'email' => $suspended->email, 'role' => 'Admin'])->assertSessionHasErrors('email');
        $this->assertSame(MembershipStatus::Suspended, $this->membershipOf($a, $suspended)->status);
    }

    public function test_setup_link_is_only_sent_to_accounts_that_have_never_signed_in(): void
    {
        Notification::fake();
        [$a, $admin] = $this->provision('Alpha');
        $veteran = User::factory()->create(['last_login_at' => now()]);

        $this->actingInTenant($admin, $a)->post('/team/members', ['name' => 'v', 'email' => $veteran->email, 'role' => 'Sales Agent']);
        $this->actingInTenant($admin, $a)->post('/team/members', ['name' => 'f', 'email' => 'fresh@example.test', 'role' => 'Sales Agent']);

        Notification::assertNotSentTo($veteran, ResetPassword::class);
        Notification::assertSentTo(User::query()->where('email', 'fresh@example.test')->first(), ResetPassword::class);
    }
}
