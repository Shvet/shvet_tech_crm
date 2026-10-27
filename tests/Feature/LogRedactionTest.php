<?php

namespace Tests\Feature;

use App\Logging\RedactSensitiveData;
use App\Support\Facades\Tenancy;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LogRedactionTest extends TestCase
{
    public function test_real_log_channels_redact_secrets_and_stamp_the_tenant(): void
    {
        $path = storage_path('logs/redaction-test-'.uniqid().'.log');
        config(['logging.channels.redtest' => ['driver' => 'single', 'path' => $path, 'level' => 'debug', 'tap' => [RedactSensitiveData::class]]]);

        [$tenant] = $this->provision('Alpha');

        Tenancy::runAs($tenant, fn () => Log::channel('redtest')->warning(
            'Graph call failed for ravi@example.com with access_token=EAAsecretsecretsecretsecretsecretsecretsecret1',
            ['headers' => ['Authorization' => 'Bearer topsecret'], 'app_secret' => 'shh', 'phone' => '+919876543210'],
        ));

        $contents = file_get_contents($path);
        @unlink($path);

        foreach (['ravi@example.com', 'EAAsecret', 'topsecret', 'shh', '9876543210'] as $leak) {
            $this->assertStringNotContainsString($leak, $contents);
        }
        $this->assertStringContainsString('"tenant_id":'.$tenant->id, $contents);
    }

    public function test_default_file_channels_have_the_redaction_tap_configured(): void
    {
        foreach (['single', 'daily', 'stderr'] as $channel) {
            $this->assertContains(RedactSensitiveData::class, config("logging.channels.$channel.tap"), "$channel is missing the redaction tap");
        }
    }
}
