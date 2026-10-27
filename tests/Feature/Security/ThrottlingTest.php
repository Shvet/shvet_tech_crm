<?php

namespace Tests\Feature\Security;

use App\Enums\RoleName;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ThrottlingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['login|127.0.0.1', 'pwreq|127.0.0.1', 'pwreset|127.0.0.1'] as $key) {
            RateLimiter::clear($key);
        }
    }

    public function test_login_attempts_do_not_consume_the_password_reset_budget(): void
    {
        Notification::fake();
        [$a] = $this->provision('Alpha');
        $user = $this->member($a, RoleName::SalesAgent);

        // 20 failed logins from the same client IP ...
        for ($i = 0; $i < 20; $i++) {
            $this->post('/login', ['email' => "ghost$i@example.test", 'password' => 'x']);
        }

        // ... must not exhaust the (much smaller) reset-request limit.
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_requests_are_limited_per_ip(): void
    {
        Notification::fake();

        foreach (range(1, 6) as $i) {
            $this->post('/forgot-password', ['email' => "u$i@example.test"])->assertSessionHasNoErrors();
        }

        $this->postJson('/forgot-password', ['email' => 'u7@example.test'])->assertStatus(429);
    }

    public function test_reset_requests_are_limited_per_mailbox_across_ips(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.$i"])
                ->post('/forgot-password', ['email' => 'victim@example.test'])->assertSessionHasNoErrors();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->postJson('/forgot-password', ['email' => 'Victim@Example.test'])->assertStatus(429);
    }

    public function test_reset_submissions_are_limited_per_ip(): void
    {
        foreach (range(1, 6) as $i) {
            $this->post('/reset-password', ['token' => 'bad', 'email' => 'a@example.test', 'password' => 'x', 'password_confirmation' => 'x']);
        }

        $this->postJson('/reset-password', ['token' => 'bad', 'email' => 'a@example.test', 'password' => 'x', 'password_confirmation' => 'x'])->assertStatus(429);
    }
}
