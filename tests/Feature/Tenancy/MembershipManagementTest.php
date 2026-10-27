<?php

namespace Tests\Feature\Tenancy;

use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class MembershipManagementTest extends TestCase
{
    private function membershipOf(Tenant $tenant, User $user): TenantMembership
    {
        return $this->runIn($tenant, fn () => TenantMembership::query()->where('user_id', $user->id)->firstOrFail());
    }

    public function test_inviting_a_new_person_creates_an_invited_membership_and_sends_a_setup_link(): void
    {
        Notification::fake();
        [$a, $admin] = $this->provision('Alpha');

        $this->actingInTenant($admin, $a)->post('/team/members', [
            'name' => 'New Agent', 'email' => 'New.Agent@Example.test', 'role' => 'Sales Agent',
        ])->assertRedirect();

        $user = User::query()->where('email', 'new.agent@example.test')->firstOrFail();
        $membership = $this->membershipOf($a, $user);

        $this->assertSame(MembershipStatus::Invited, $membership->status);
        $this->assertTrue($this->runIn($a, fn () => $user->fresh()->hasRole('Sales Agent')));
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertSame(1, $this->runIn($a, fn () => AuditLog::query()->where('action', 'member.invited')->count()));
    }

    public function test_invited_member_has_no_access_until_invitation_is_active(): void
    {
        [$a, $admin] = $this->provision('Alpha');
        $invited = $this->member($a, RoleName::SalesAgent, MembershipStatus::Invited);

        $this->actingAs($invited)->get('/dashboard')->assertRedirect(route('workspaces.index'));
    }

    public function test_new_user_sets_a_password_but_still_has_to_accept_the_invitation_explicitly(): void
    {
        Notification::fake();
        [$a, $admin] = $this->provision('Alpha');
        $this->actingInTenant($admin, $a)->post('/team/members', ['name' => 'Nia', 'email' => 'nia@example.test', 'role' => 'Sales Agent']);

        $user = User::query()->where('email', 'nia@example.test')->firstOrFail();
        $token = Password::createToken($user);

        $this->asGuest();
        $this->post('/reset-password', [
            'token' => $token, 'email' => 'nia@example.test',
            'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x',
        ])->assertRedirect(route('login'));

        // Proving control of the mailbox is not consent to join: still invited, and no access yet.
        $this->assertSame(MembershipStatus::Invited, $this->membershipOf($a, $user)->status);

        $this->post('/login', ['email' => 'nia@example.test', 'password' => 'Str0ng-Passw0rd!x']);
        $this->get('/dashboard')->assertRedirect(route('workspaces.index'));
        $this->post(route('workspaces.accept', $a))->assertRedirect(route('workspaces.index'));
        $this->assertSame(MembershipStatus::Active, $this->membershipOf($a, $user)->status);
        $this->get('/dashboard')->assertOk();
    }

    public function test_a_password_reset_never_activates_invitations_from_other_workspaces(): void
    {
        Notification::fake();
        [$a, $adminA] = $this->provision('Alpha');
        [$b, $adminB] = $this->provision('Beta');
        $this->actingInTenant($adminA, $a)->post('/team/members', ['name' => 'Nia', 'email' => 'nia@example.test', 'role' => 'Sales Agent']);
        $this->actingInTenant($adminB, $b)->post('/team/members', ['name' => 'Nia', 'email' => 'nia@example.test', 'role' => 'Sales Manager']);
        $user = User::query()->where('email', 'nia@example.test')->firstOrFail();

        $this->asGuest();
        $this->post('/reset-password', ['token' => Password::createToken($user), 'email' => 'nia@example.test', 'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x']);

        $this->assertSame(MembershipStatus::Invited, $this->membershipOf($a, $user)->status);
        $this->assertSame(MembershipStatus::Invited, $this->membershipOf($b, $user)->status);
    }

    public function test_existing_user_must_accept_an_invitation_explicitly(): void
    {
        [$a, $adminA] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');
        $user = $this->member($b, RoleName::SalesAgent);
        $user->forceFill(['last_login_at' => now()])->save();

        $this->actingInTenant($adminA, $a)->post('/team/members', ['name' => $user->name, 'email' => $user->email, 'role' => 'Sales Manager']);
        $this->assertSame(MembershipStatus::Invited, $this->membershipOf($a, $user)->status);

        // Resetting a password does NOT accept an invitation.
        $this->asGuest();
        $token = Password::createToken($user);
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x']);
        $this->assertSame(MembershipStatus::Invited, $this->membershipOf($a, $user)->status);

        $this->actingAs($user->fresh())->post(route('workspaces.accept', $a))->assertRedirect(route('workspaces.index'));
        $this->assertSame(MembershipStatus::Active, $this->membershipOf($a, $user)->status);
    }

    public function test_one_cannot_accept_someone_elses_invitation_or_an_unrelated_tenants(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');
        $agentB = $this->member($b, RoleName::SalesAgent);

        $this->actingAs($agentB)->post(route('workspaces.accept', $a))->assertForbidden();
        $this->assertNull($this->runIn($a, fn () => TenantMembership::query()->where('user_id', $agentB->id)->first()));
    }

    public function test_cannot_invite_an_existing_member_twice_but_can_reinvite_a_removed_one(): void
    {
        [$a, $admin] = $this->provision('Alpha');
        $agent = $this->member($a, RoleName::SalesAgent);
        $membership = $this->membershipOf($a, $agent);

        $this->actingInTenant($admin, $a)->post('/team/members', ['name' => 'x', 'email' => $agent->email, 'role' => 'Sales Agent'])
            ->assertSessionHasErrors('email');

        $this->actingInTenant($admin, $a)->delete("/team/members/{$membership->id}");
        $this->assertSame(MembershipStatus::Removed, $this->membershipOf($a, $agent)->status);

        $this->actingInTenant($admin, $a)->post('/team/members', ['name' => 'x', 'email' => $agent->email, 'role' => 'Sales Manager'])
            ->assertSessionHasNoErrors();
        $this->assertSame(MembershipStatus::Invited, $this->membershipOf($a, $agent)->status);
    }

    public function test_role_validation_rejects_unknown_roles_and_never_trusts_ids(): void
    {
        [$a, $admin] = $this->provision('Alpha');

        $this->actingInTenant($admin, $a)->post('/team/members', ['name' => 'x', 'email' => 'x@example.test', 'role' => 'Super Admin'])
            ->assertSessionHasErrors('role');
        $this->actingInTenant($admin, $a)->post('/team/members', ['name' => 'x', 'email' => 'x@example.test', 'role' => '1'])
            ->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'x@example.test']);
    }

    public function test_role_change_and_audit_trail(): void
    {
        [$a, $admin] = $this->provision('Alpha');
        $agent = $this->member($a, RoleName::SalesAgent);
        $membership = $this->membershipOf($a, $agent);

        $this->actingInTenant($admin, $a)->patch("/team/members/{$membership->id}", ['role' => 'Sales Manager'])->assertRedirect();

        $this->runIn($a, function () use ($agent) {
            $fresh = User::query()->find($agent->id);
            $this->assertTrue($fresh->hasRole('Sales Manager'));
            $this->assertFalse($fresh->hasRole('Sales Agent'));
            $this->assertSame(1, AuditLog::query()->where('action', 'member.role_changed')->count());
        });
    }

    public function test_suspend_reinstate_and_remove_members(): void
    {
        [$a, $admin] = $this->provision('Alpha');
        $agent = $this->member($a, RoleName::SalesAgent);
        $m = $this->membershipOf($a, $agent);

        $this->actingInTenant($admin, $a)->patch("/team/members/{$m->id}", ['status' => 'suspended']);
        $this->assertSame(MembershipStatus::Suspended, $this->membershipOf($a, $agent)->status);
        $this->actingInTenant($agent, $a)->get('/dashboard')->assertRedirect(route('workspaces.index'));

        $this->actingInTenant($admin, $a)->patch("/team/members/{$m->id}", ['status' => 'active']);
        $this->actingInTenant($agent, $a)->get('/dashboard')->assertOk();

        $this->actingInTenant($admin, $a)->delete("/team/members/{$m->id}");
        $this->assertSame(MembershipStatus::Removed, $this->membershipOf($a, $agent)->status);
        $this->actingInTenant($agent, $a)->get('/dashboard')->assertRedirect(route('workspaces.index'));
    }

    public function test_status_input_cannot_set_invited_or_removed_via_update(): void
    {
        [$a, $admin] = $this->provision('Alpha');
        $agent = $this->member($a, RoleName::SalesAgent);
        $m = $this->membershipOf($a, $agent);

        $this->actingInTenant($admin, $a)->patch("/team/members/{$m->id}", ['status' => 'removed'])->assertSessionHasErrors('status');
        $this->actingInTenant($admin, $a)->patch("/team/members/{$m->id}", ['status' => 'invited'])->assertSessionHasErrors('status');
    }

    public function test_the_last_active_admin_cannot_be_demoted_suspended_or_removed(): void
    {
        [$a, $admin] = $this->provision('Alpha');
        $m = $this->membershipOf($a, $admin);

        $this->actingInTenant($admin, $a)->patch("/team/members/{$m->id}", ['role' => 'Sales Agent'])->assertSessionHasErrors('member');
        $this->actingInTenant($admin, $a)->patch("/team/members/{$m->id}", ['status' => 'suspended'])->assertSessionHasErrors('member');
        $this->actingInTenant($admin, $a)->delete("/team/members/{$m->id}")->assertSessionHasErrors('member');

        $this->assertTrue($this->runIn($a, fn () => $admin->fresh()->hasRole('Admin')));
        $this->assertSame(MembershipStatus::Active, $this->membershipOf($a, $admin)->status);
    }

    public function test_an_admin_can_step_down_once_another_admin_exists(): void
    {
        [$a, $admin] = $this->provision('Alpha');
        $second = $this->member($a, RoleName::Admin);
        $m = $this->membershipOf($a, $admin);

        $this->actingInTenant($admin, $a)->patch("/team/members/{$m->id}", ['role' => 'Sales Agent'])->assertSessionHasNoErrors();

        // ... and now the second admin is the last one.
        $m2 = $this->membershipOf($a, $second);
        $this->actingInTenant($second, $a)->patch("/team/members/{$m2->id}", ['role' => 'Sales Agent'])->assertSessionHasErrors('member');
    }

    public function test_admin_counts_of_other_tenants_do_not_satisfy_the_last_admin_rule(): void
    {
        [$a, $adminA] = $this->provision('Alpha');
        [$b] = $this->provision('Beta'); // has its own admin
        $m = $this->membershipOf($a, $adminA);

        $this->actingInTenant($adminA, $a)->patch("/team/members/{$m->id}", ['role' => 'Sales Agent'])->assertSessionHasErrors('member');
    }

    public function test_inactive_admins_do_not_count_towards_the_minimum(): void
    {
        [$a, $admin] = $this->provision('Alpha');
        $second = $this->member($a, RoleName::Admin, MembershipStatus::Suspended);
        $m = $this->membershipOf($a, $admin);

        $this->actingInTenant($admin, $a)->delete("/team/members/{$m->id}")->assertSessionHasErrors('member');
    }
}
