<?php

namespace Tests\Feature\Meta;

use App\Enums\MetaConnectionStatus;
use App\Models\MetaConnection;
use App\Models\MetaDataDeletionRequest;
use App\Models\MetaPage;
use App\Services\Meta\SignedRequest;
use App\Support\Facades\Tenancy;
use Illuminate\Support\Facades\Http;
use Tests\Support\MetaTestHelpers;
use Tests\TestCase;

class DataDeletionCallbackTest extends TestCase
{
    use MetaTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        Http::preventStrayRequests(); // the callbacks must never call Meta
    }

    private function signedRequest(array $payload, ?string $secret = null): string
    {
        $b64 = fn (string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $encoded = $b64(json_encode($payload + ['algorithm' => 'HMAC-SHA256', 'issued_at' => time()]));

        return $b64(hash_hmac('sha256', $encoded, $secret ?? $this->metaSecret, true)).'.'.$encoded;
    }

    public function test_deletion_callback_returns_a_confirmation_code_and_status_url(): void
    {
        [$acme] = $this->provision('Acme');
        [$c] = $this->connectMeta($acme);
        Tenancy::withoutScope(fn () => $c->forceFill(['meta_user_id' => '555'])->save());

        $response = $this->post('/webhooks/meta/data-deletion', ['signed_request' => $this->signedRequest(['user_id' => '555'])])->assertOk();

        $code = (string) $response->json('confirmation_code');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{16}$/', $code);
        $this->assertSame(route('legal.data-deletion.status', ['code' => $code]), $response->json('url'));

        $this->get('/data-deletion/status?code='.$code)->assertOk()->assertSee('Completed');
    }

    public function test_deletion_and_deauthorize_act_only_on_the_signed_user(): void
    {
        [$acme] = $this->provision('Acme');
        [$globex] = $this->provision('Globex');
        [$a, $pageA] = $this->connectMeta($acme, pageId: '100000000000001');
        [$g, $pageG] = $this->connectMeta($globex, pageId: '100000000000002');
        [$other, $otherPage] = $this->connectMeta($acme, pageId: '100000000000003');
        Tenancy::withoutScope(function () use ($a, $g, $other) {
            $a->forceFill(['meta_user_id' => '555'])->save();
            $g->forceFill(['meta_user_id' => '555'])->save();
            $other->forceFill(['meta_user_id' => '777'])->save();
        });

        $this->post('/webhooks/meta/data-deletion', ['signed_request' => $this->signedRequest(['user_id' => '555'])])->assertOk();

        Tenancy::withoutScope(function () use ($a, $g, $other, $pageA, $pageG, $otherPage) {
            foreach ([$a, $g] as $c) {
                $c->refresh();
                $this->assertSame(MetaConnectionStatus::Disconnected, $c->status);
                $this->assertStringStartsWith('deleted-', $c->meta_user_id);
                $this->assertNull($c->meta_user_name);
                $this->assertNull($c->granted_permissions);
            }
            $this->assertFalse(MetaPage::query()->whereKey([$pageA->id, $pageG->id])->exists());

            $other->refresh();
            $this->assertSame(MetaConnectionStatus::Active, $other->status);
            $this->assertSame('777', $other->meta_user_id);
            $this->assertTrue(MetaPage::query()->whereKey($otherPage->id)->exists());
        });

        $record = MetaDataDeletionRequest::query()->sole();
        $this->assertSame(2, $record->connections_affected);
        $this->assertSame(MetaDataDeletionRequest::KIND_DELETION, $record->kind);
        $this->assertNotSame('555', $record->meta_user_hash);
    }

    public function test_deauthorize_disconnects_but_keeps_the_user_id(): void
    {
        [$acme] = $this->provision('Acme');
        [$c, $page] = $this->connectMeta($acme);
        Tenancy::withoutScope(fn () => $c->forceFill(['meta_user_id' => '555'])->save());

        $this->post('/webhooks/meta/deauthorize', ['signed_request' => $this->signedRequest(['user_id' => '555'])])->assertOk();

        Tenancy::withoutScope(function () use ($c, $page) {
            $c->refresh();
            $this->assertSame(MetaConnectionStatus::Disconnected, $c->status);
            $this->assertSame('555', $c->meta_user_id);
            $this->assertFalse(MetaPage::query()->whereKey($page->id)->exists());
        });
        $this->assertSame(MetaDataDeletionRequest::KIND_DEAUTHORIZE, MetaDataDeletionRequest::query()->sole()->kind);
    }

    public function test_unknown_user_still_gets_a_confirmation_code(): void
    {
        $this->post('/webhooks/meta/data-deletion', ['signed_request' => $this->signedRequest(['user_id' => '999'])])
            ->assertOk()->assertJsonStructure(['url', 'confirmation_code']);
        $this->assertSame(0, MetaDataDeletionRequest::query()->sole()->connections_affected);
    }

    public function test_bad_signatures_and_payloads_are_rejected_and_change_nothing(): void
    {
        [$acme] = $this->provision('Acme');
        [$c] = $this->connectMeta($acme);
        Tenancy::withoutScope(fn () => $c->forceFill(['meta_user_id' => '555'])->save());

        foreach ([
            $this->signedRequest(['user_id' => '555'], 'wrong-secret'),
            $this->signedRequest(['user_id' => 'not-numeric']),
            $this->signedRequest([]),
            'garbage',
            'a.b.c',
            '',
        ] as $bad) {
            $this->post('/webhooks/meta/data-deletion', ['signed_request' => $bad])->assertForbidden();
            $this->post('/webhooks/meta/deauthorize', ['signed_request' => $bad])->assertForbidden();
        }

        $this->assertSame(0, MetaDataDeletionRequest::query()->count());
        $this->assertSame(MetaConnectionStatus::Active, Tenancy::withoutScope(fn () => MetaConnection::query()->find($c->id))->status);
    }

    public function test_wrong_algorithm_is_rejected(): void
    {
        $b64 = fn (string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $encoded = $b64(json_encode(['algorithm' => 'none', 'user_id' => '555']));
        $signed = $b64(hash_hmac('sha256', $encoded, $this->metaSecret, true)).'.'.$encoded;

        $this->assertNull(SignedRequest::parse($signed, $this->metaSecret));
    }

    public function test_callbacks_refuse_to_run_without_an_app_secret(): void
    {
        config(['meta.app_secret' => '']);

        $this->post('/webhooks/meta/data-deletion', ['signed_request' => $this->signedRequest(['user_id' => '555'])])->assertStatus(503);
    }
}
