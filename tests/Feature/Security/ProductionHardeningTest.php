<?php

namespace Tests\Feature\Security;

use App\Enums\RoleName;
use App\Models\User;
use App\Support\ProductionSafeguards;
use App\Support\Redactor;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProductionHardeningTest extends TestCase
{
    private function goodProductionConfig(): void
    {
        config([
            'app.debug' => false, 'app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.url' => 'https://crm.example.test',
            'session.secure' => true, 'session.http_only' => true, 'session.driver' => 'database',
            'mail.default' => 'smtp', 'crm.enforce_host' => true,
        ]);
    }

    public function test_safeguards_pass_on_a_correct_production_configuration(): void
    {
        $this->goodProductionConfig();

        $this->assertSame([], ProductionSafeguards::critical());
    }

    public function test_each_unsafe_setting_is_reported_as_critical(): void
    {
        $this->goodProductionConfig();
        $cases = [
            ['app.debug', true, 'APP_DEBUG'],
            ['app.url', 'http://crm.example.test', 'APP_URL'],
            ['session.secure', false, 'SESSION_SECURE_COOKIE'],
            ['session.http_only', false, 'HttpOnly'],
            ['mail.default', 'log', 'MAIL_MAILER'],
            ['crm.enforce_host', false, 'CRM_ENFORCE_HOST'],
            ['session.driver', 'cookie', 'SESSION_DRIVER'],
            ['app.key', '', 'APP_KEY'],
        ];

        foreach ($cases as [$key, $value, $needle]) {
            $this->goodProductionConfig();
            config([$key => $value]);
            $joined = implode(' | ', ProductionSafeguards::critical());
            $this->assertStringContainsString($needle, $joined, "$key=".var_export($value, true));
        }
    }

    public function test_preflight_command_fails_on_bad_config_and_passes_on_good_config(): void
    {
        $this->goodProductionConfig();
        $this->artisan('crm:preflight')->assertSuccessful();

        config(['app.debug' => true]);
        $this->artisan('crm:preflight')->expectsOutputToContain('CRITICAL')->assertFailed();
    }

    public function test_session_cookie_defaults_are_hardened_for_production(): void
    {
        $file = file_get_contents(config_path('session.php'));

        $this->assertStringContainsString("env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production')", $file);
        $this->assertStringContainsString("env('SESSION_ENCRYPT', env('APP_ENV') === 'production')", $file);
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_unknown_host_header_is_rejected_when_enforced(): void
    {
        config(['crm.enforce_host' => true, 'app.url' => 'https://crm.example.test']);

        $this->get('http://evil.test/login')->assertStatus(400);
        $this->get('http://crm.example.test/login')->assertOk();
    }

    public function test_reset_links_cannot_be_poisoned_through_the_host_header(): void
    {
        Notification::fake();
        config(['crm.enforce_host' => true, 'app.url' => 'https://crm.example.test']);
        [$a] = $this->provision('Alpha');
        $user = $this->member($a, RoleName::SalesAgent);

        $this->post('http://evil.test/forgot-password', ['email' => $user->email])->assertStatus(400);
        Notification::assertNothingSent();

        $this->post('http://crm.example.test/forgot-password', ['email' => $user->email]);
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use ($user) {
            return str_starts_with($n->toMail($user)->actionUrl, 'http://crm.example.test/reset-password/');
        });
    }

    public function test_demo_seeder_only_runs_in_local_or_testing(): void
    {
        foreach (['production', 'staging'] as $env) {
            $this->app['env'] = $env;
            try {
                (new DatabaseSeeder)->run();
                $this->fail("Seeder must refuse in $env");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('local/testing', $e->getMessage());
            }
        }
        $this->assertSame(0, User::query()->count());
    }

    public function test_password_reset_tokens_in_urls_are_redacted_from_logs(): void
    {
        $out = Redactor::redactString('Click https://crm.example.test/reset-password/3f9a1c2b7d8e4f60a1b2c3d4e5f60718?email=a');

        $this->assertStringNotContainsString('3f9a1c2b7d8e4f60', $out);
        $this->assertStringContainsString('reset-password/', $out);
    }

    public function test_authenticated_pages_are_not_publicly_cacheable(): void
    {
        [$a, $admin] = $this->provision('Alpha');

        $cc = $this->actingInTenant($admin, $a)->get('/dashboard')->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cc);
        $this->assertStringNotContainsString('public', $cc);
    }
}
