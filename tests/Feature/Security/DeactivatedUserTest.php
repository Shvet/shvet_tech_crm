<?php

namespace Tests\Feature\Security;

use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Models\TenantMembership;
use Tests\TestCase;

/** A deactivated account must lose EVERY authenticated route immediately, not only tenant routes. */
class DeactivatedUserTest extends TestCase
{
    public function test_deactivated_user_with_a_live_session_is_locked_out_of_non_tenant_routes_too(): void
    {
        [$a] = $this->provision('Alpha');
        $user = $this->member($a, RoleName::SalesAgent);
        $this->actingInTenant($user, $a)->get('/profile')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get('/profile')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_deactivated_user_cannot_use_profile_or_workspace_actions(): void
    {
        [$a] = $this->provision('Alpha');
        $user = $this->member($a, RoleName::SalesAgent);
        $invitee = $this->member($a, RoleName::SalesAgent, MembershipStatus::Invited);
        $invitee->forceFill(['is_active' => false])->save();
        $user->forceFill(['is_active' => false])->save();

        foreach ([
            ['get', '/workspaces'], ['patch', '/profile'], ['put', '/profile/password'],
            ['post', route('workspaces.select', $a)], ['post', route('workspaces.accept', $a)],
        ] as [$method, $url]) {
            $this->asGuest();
            $this->actingAs($user)->{$method}($url)->assertRedirect('/login');
        }

        $this->asGuest();
        $this->actingAs($invitee)->post(route('workspaces.accept', $a))->assertRedirect('/login');
        $this->assertSame(MembershipStatus::Invited, $this->runIn($a, fn () => TenantMembership::query()->where('user_id', $invitee->id)->first())->status);
    }
}
