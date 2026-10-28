<?php

namespace Tests\Feature\Meta;

use App\Exceptions\MetaApiException;
use App\Exceptions\MetaConnectionException;
use App\Services\Meta\GraphClient;
use App\Support\ProductionSafeguards;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\MetaTestHelpers;
use Tests\TestCase;

/** All responses are MOCKED approximations of Meta's documented behavior; none of this proves real-API compatibility. */
class GraphClientTest extends TestCase
{
    use MetaTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
    }

    private function error(int $httpStatus, ?int $code, array $headers = [], ?int $sub = null): MetaApiException
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response($code === null ? '' : ['error' => ['code' => $code, 'error_subcode' => $sub, 'message' => 'leaky TOKEN EAAB'.str_repeat('q', 60).' for user a@b.test', 'fbtrace_id' => 'TRACE1']], $httpStatus, $headers)]);

        try {
            app(GraphClient::class)->lead('1', 'PAGE-TOKEN-SECRET');
        } catch (MetaApiException $e) {
            return $e;
        }
        $this->fail('expected MetaApiException');
    }

    public function test_error_classification(): void
    {
        $this->assertSame('auth', $this->error(401, 190)->category);
        $this->assertSame('unknown', $this->error(400, 9999)->category);
        $this->assertSame('permission', $this->error(403, 10)->category);
        $this->assertSame('permission', $this->error(400, 200)->category);
        $this->assertSame('permission', $this->error(400, 299)->category);
        $this->assertSame('rate_limit', $this->error(400, 4)->category);
        $this->assertSame('rate_limit', $this->error(400, 17)->category);
        $this->assertSame('rate_limit', $this->error(400, 613)->category);
        $this->assertSame('rate_limit', $this->error(429, null)->category);
        $this->assertSame('transient', $this->error(500, 2)->category);
        $this->assertSame('transient', $this->error(503, null)->category);
        $this->assertSame('invalid_request', $this->error(400, 100)->category);
        $this->assertTrue($this->error(503, null)->isRetryable());
        $this->assertTrue($this->error(429, 4)->isRetryable());
        $this->assertFalse($this->error(401, 190)->isRetryable());
        $this->assertFalse($this->error(403, 10)->isRetryable());
        $this->assertTrue($this->error(401, 190)->requiresReauthorization());
    }

    public function test_retry_after_and_business_usage_headers_are_honoured(): void
    {
        $this->assertSame(120, $this->error(429, 4, ['Retry-After' => '120'])->retryAfterSeconds);
        $usage = json_encode(['123' => [['type' => 'ads_management', 'estimated_time_to_regain_access' => 5]]]);
        $this->assertSame(300, $this->error(400, 17, ['X-Business-Use-Case-Usage' => $usage])->retryAfterSeconds);
    }

    public function test_exceptions_never_carry_tokens_urls_user_data_or_meta_free_text(): void
    {
        $e = $this->error(401, 190);
        $dump = $e->getMessage().$e->adminMessage().$e->code().$e->getTraceAsString();

        foreach (['PAGE-TOKEN-SECRET', 'EAAB', 'a@b.test', 'leaky', 'graph.example.test', $this->metaSecret] as $needle) {
            $this->assertStringNotContainsString($needle, $dump);
        }
    }

    public function test_network_failures_are_transient_not_fatal(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout https://graph.example.test/v99.0/1?access_token=SECRET'));

        try {
            app(GraphClient::class)->lead('1', 'PAGE-TOKEN-SECRET');
            $this->fail('expected exception');
        } catch (MetaApiException $e) {
            $this->assertSame('transient', $e->category);
            $this->assertStringNotContainsString('SECRET', $e->getMessage());
            $this->assertTrue($e->isRetryable());
        }
    }

    public function test_requests_use_the_configured_version_and_base_url(): void
    {
        Http::fake(['*' => Http::response(['id' => '1'])]);
        app(GraphClient::class)->lead('1', 't');

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://graph.example.test/v99.0/1?'));
    }

    public function test_missing_graph_version_refuses_to_call_meta(): void
    {
        config(['meta.graph_version' => '']);
        Http::fake();

        $this->expectException(MetaConnectionException::class);
        try {
            app(GraphClient::class)->lead('1', 't');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_pagination_is_bounded(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => '1', 'name' => 'f']], 'paging' => ['cursors' => ['after' => 'CUR'], 'next' => 'https://graph.example.test/v99.0/next']])]);

        $forms = app(GraphClient::class)->leadForms('9', 't');

        $this->assertLessThanOrEqual(5, count($forms));
        Http::assertSentCount(5);
    }

    public function test_pagination_does_not_follow_foreign_hosts(): void
    {
        Http::fake(['graph.example.test/*' => Http::response(['data' => [['id' => '1', 'name' => 'f']], 'paging' => ['cursors' => ['after' => 'CUR'], 'next' => 'https://evil.test/steal']]), '*' => Http::response('', 500)]);

        app(GraphClient::class)->leadForms('9', 'PAGE-TOKEN-SECRET');

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'evil.test'));
    }

    public function test_authorization_url_contains_no_secret_and_uses_trusted_redirect(): void
    {
        $url = app(GraphClient::class)->authorizationUrl('STATE');

        $this->assertStringNotContainsString($this->metaSecret, $url);
        $this->assertStringContainsString(urlencode('https://crm.example.test/integrations/meta/callback'), $url);
        $this->assertStringContainsString('state=STATE', $url);
    }

    public function test_preflight_flags_half_configured_meta(): void
    {
        config(['meta.app_id' => '1', 'meta.app_secret' => '', 'meta.webhook_verify_token' => 'short', 'meta.graph_version' => '']);

        $critical = implode('|', ProductionSafeguards::critical());

        $this->assertStringContainsString('half-configured', $critical);
        $this->assertStringContainsString('META_WEBHOOK_VERIFY_TOKEN', $critical);
    }

    public function test_preflight_is_silent_when_meta_is_unused(): void
    {
        config(['meta.app_id' => '', 'meta.app_secret' => '', 'meta.webhook_verify_token' => '']);

        $this->assertStringNotContainsString('Meta', implode('|', ProductionSafeguards::critical()));
    }
}
