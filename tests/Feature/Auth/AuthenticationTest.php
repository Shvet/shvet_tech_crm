<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Facades\Tenancy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    private function userInTenant(string $password = 'Correct-Horse-9'): array
    {
        [$tenant] = $this->provision('Alpha');
        $user = $this->member($tenant, RoleName::SalesAgent, userAttributes: ['password' => $password]);

        return [$tenant, $user];
    }

    public function test_login_page_renders_for_guests(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn ($p) => $p->component('Auth/Login'));
    }

    public function test_user_can_log_in_and_lands_in_their_workspace(): void
    {
        [$tenant, $user] = $this->userInTenant();

        $this->post('/login', ['email' => $user->email, 'password' => 'Correct-Horse-9'])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->get('/dashboard')->assertOk();
    }

    public function test_session_id_is_regenerated_on_login(): void
    {
        [, $user] = $this->userInTenant();
        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', ['email' => $user->email, 'password' => 'Correct-Horse-9']);

        $this->assertNotSame($before, session()->getId());
    }

    public function test_wrong_password_and_unknown_email_get_the_same_error(): void
    {
        [, $user] = $this->userInTenant();

        $this->post('/login', ['email' => $user->email, 'password' => 'nope'])->assertSessionHasErrors('email');
        $wrong = session('errors')->first('email');

        $this->post('/login', ['email' => 'ghost@example.test', 'password' => 'nope'])->assertSessionHasErrors('email');
        $unknown = session('errors')->first('email');

        $this->assertNotEmpty($wrong);
        $this->assertSame($wrong, $unknown);
        $this->assertGuest();
    }

    public function test_deactivated_users_cannot_log_in(): void
    {
        [, $user] = $this->userInTenant();
        $user->forceFill(['is_active' => false])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'Correct-Horse-9'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_five_failures(): void
    {
        [, $user] = $this->userInTenant();
        RateLimiter::clear(Str::transliterate(strtolower($user->email).'|127.0.0.1'));

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'bad'])->assertSessionHasErrors('email');
        }

        // Even the CORRECT password is refused while locked out.
        $this->postJson('/login', ['email' => $user->email, 'password' => 'Correct-Horse-9'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_logout_ends_the_session(): void
    {
        [$tenant, $user] = $this->userInTenant();

        $this->actingInTenant($user, $tenant)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_authenticated_users_are_bounced_from_guest_pages(): void
    {
        [$tenant, $user] = $this->userInTenant();

        $this->actingInTenant($user, $tenant)->get('/login')->assertRedirect(route('dashboard'));
    }

    public function test_there_is_no_public_registration(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'x', 'email' => 'x@example.test', 'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x'])->assertNotFound();
        $this->assertDatabaseMissing('users', ['email' => 'x@example.test']);
    }

    public function test_reset_link_request_gives_identical_answers_for_known_and_unknown_emails(): void
    {
        Notification::fake();
        [, $user] = $this->userInTenant();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();
        $known = session('status');

        $this->post('/forgot-password', ['email' => 'ghost@example.test'])->assertSessionHasNoErrors();
        $unknown = session('status');

        $this->assertNotEmpty($known);
        $this->assertSame($known, $unknown);
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        Notification::assertNothingSentTo(User::factory()->make(['email' => 'ghost@example.test']));
    }

    public function test_password_can_be_reset_with_a_valid_token_and_policy_is_enforced(): void
    {
        [, $user] = $this->userInTenant();
        $token = Password::createToken($user);

        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');

        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x'])
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('Str0ng-Passw0rd!x', $user->fresh()->password));
        // Token is single-use.
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'An0ther-Passw0rd!y', 'password_confirmation' => 'An0ther-Passw0rd!y'])
            ->assertSessionHasErrors('email');
    }

    public function test_invalid_reset_token_is_rejected(): void
    {
        [, $user] = $this->userInTenant();

        $this->post('/reset-password', ['token' => 'bogus', 'email' => $user->email, 'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x'])
            ->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('Correct-Horse-9', $user->fresh()->password));
    }

    public function test_profile_update_cannot_change_privileged_fields(): void
    {
        [$tenant, $user] = $this->userInTenant();

        $this->actingInTenant($user, $tenant)->patch('/profile', [
            'name' => 'New Name', 'phone' => '+919999999999',
            'email' => 'hijack@example.test', 'is_active' => false, 'password' => 'x',
        ])->assertSessionHasNoErrors();

        $fresh = $user->fresh();
        $this->assertSame('New Name', $fresh->name);
        $this->assertSame($user->email, $fresh->email);
        $this->assertTrue($fresh->is_active);
        $this->assertTrue(Hash::check('Correct-Horse-9', $fresh->password));
    }

    public function test_password_change_requires_the_current_password_and_is_audited(): void
    {
        [$tenant, $user] = $this->userInTenant();

        $this->actingInTenant($user, $tenant)->put('/profile/password', [
            'current_password' => 'wrong', 'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x',
        ])->assertSessionHasErrors('current_password');

        $this->actingInTenant($user, $tenant)->put('/profile/password', [
            'current_password' => 'Correct-Horse-9', 'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Str0ng-Passw0rd!x', $user->fresh()->password));
        $this->assertSame(1, Tenancy::withoutScope(fn () => AuditLog::query()->where('action', 'user.password_changed')->whereNull('tenant_id')->count()));
    }

    public function test_responses_carry_security_headers(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_csrf_protection_is_part_of_the_web_stack(): void
    {
        $web = app(Kernel::class)->getMiddlewareGroups()['web'];

        $this->assertNotEmpty(
            array_filter($web, fn ($m) => is_string($m) && (str_contains($m, 'PreventRequestForgery') || str_contains($m, 'VerifyCsrfToken'))),
            'CSRF middleware must be in the web group.',
        );
    }

    public function test_session_cookie_hardening_defaults(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_page_history_is_encrypted_and_cleared_on_logout(): void
    {
        [$tenant, $user] = $this->userInTenant();

        $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];

        $page = $this->actingInTenant($user, $tenant)->withHeaders($inertia)->get('/dashboard')->assertOk()->json();
        $this->assertTrue($page['encryptHistory'] ?? false, 'page props must be encrypted in browser history');

        $this->post('/logout');
        $login = $this->withHeaders($inertia)->get('/login')->json();
        $this->assertTrue($login['clearHistory'] ?? false, 'logout must tell the browser to drop its history key');
    }
}
