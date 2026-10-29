<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** `php artisan config:cache` (a mandatory deploy step) makes env() return null everywhere except config files. */
class ConfigCacheSafetyTest extends TestCase
{
    public function test_env_is_only_called_from_config_files(): void
    {
        $offenders = [];
        foreach (['app', 'bootstrap', 'routes', 'database', 'resources/views'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/bootstrap/cache/')) {
                    continue;
                }
                if (preg_match('/(?<![A-Za-z_>:$])env\s*\(/', file_get_contents($file->getPathname()))) {
                    $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $offenders, 'env() must only be used inside config/*.php');
    }

    public function test_trusted_proxy_setting_is_read_from_config_per_request(): void
    {
        Route::get('/__client-ip', fn () => request()->ip());
        config(['trustedproxy.proxies' => ['127.0.0.1']]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/__client-ip', ['X-Forwarded-For' => '203.0.113.9'])
            ->assertSee('203.0.113.9');
    }

    public function test_untrusted_forwarded_headers_are_ignored(): void
    {
        Route::get('/__client-ip', fn () => request()->ip());
        config(['trustedproxy.proxies' => []]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/__client-ip', ['X-Forwarded-For' => '203.0.113.9'])
            ->assertSee('127.0.0.1');
    }
}
