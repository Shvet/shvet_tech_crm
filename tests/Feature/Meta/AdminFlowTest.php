<?php

namespace Tests\Feature\Meta;

use App\Enums\MembershipStatus;
use App\Enums\MetaConnectionStatus;
use App\Enums\MetaEventStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\MetaConnection;
use App\Models\MetaFormMapping;
use App\Models\MetaLeadEvent;
use App\Models\MetaOauthState;
use App\Models\MetaPage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\MetaTestHelpers;
use Tests\TestCase;

class AdminFlowTest extends TestCase
{
    use MetaTestHelpers;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        config(['queue.default' => 'database']);
        [$this->tenant, $this->admin] = $this->provision('Acme');
    }

    private function asAdmin()
    {
        return $this->actingInTenant($this->admin, $this->tenant);
    }

    /** Fake the Graph endpoints the OAuth flow calls. */
    private function fakeOAuth(array $scopes = ['pages_show_list', 'pages_read_engagement', 'pages_manage_metadata', 'leads_retrieval', 'pages_manage_ads'], array $pages = [['id' => '100000000000001', 'name' => 'Acme Page', 'access_token' => 'PAGE-TOKEN-A']]): void
    {
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'USER-TOKEN-LONG', 'expires_in' => 5000000]),
            '*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'scopes' => $scopes, 'user_id' => 'u1', 'expires_at' => time() + 5000000, 'data_access_expires_at' => time() + 7000000]]),
            '*/me/accounts*' => Http::response(['data' => $pages]),
            '*/me?*' => Http::response(['id' => 'u1', 'name' => 'Page Admin']),
            '*/subscribed_apps*' => Http::response(['success' => true]),
            '*/leadgen_forms*' => Http::response(['data' => [['id' => '700000000000001', 'name' => 'Diwali form', 'status' => 'ACTIVE']]]),
        ]);
    }

    /** Start a flow and return the state Meta would echo back. */
    private function startFlow(): string
    {
        $response = $this->asAdmin()->post('/integrations/meta/connect', [], ['X-Inertia' => 'true']);
        $response->assertStatus(409); // Inertia::location
        parse_str(parse_url($response->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $q);

        return $q['state'];
    }

    // ---- authorization -----------------------------------------------------------------------------------------

    public function test_only_admins_can_use_every_meta_admin_route(): void
    {
        [$connection, $page, $mapping] = $this->connectMeta($this->tenant);
        $event = Tenancy::withoutScope(fn () => MetaLeadEvent::query()->create(['tenant_id' => $this->tenant->id, 'page_id' => '1', 'leadgen_id' => '2', 'status' => 'failed', 'received_at' => now()]));
        $routes = [
            ['get', '/integrations/meta'],
            ['post', '/integrations/meta/connect'],
            ['get', '/integrations/meta/callback?code=x&state=y'],
            ['post', "/integrations/meta/connections/{$connection->public_id}/pages"],
            ['post', "/integrations/meta/connections/{$connection->public_id}/validate"],
            ['delete', "/integrations/meta/connections/{$connection->public_id}"],
            ['post', "/integrations/meta/pages/{$page->public_id}/sync-forms"],
            ['post', "/integrations/meta/pages/{$page->public_id}/subscribe"],
            ['delete', "/integrations/meta/pages/{$page->public_id}"],
            ['put', "/integrations/meta/mappings/{$mapping->public_id}"],
            ['post', "/integrations/meta/events/{$event->public_id}/retry"],
            ['post', "/integrations/meta/events/{$event->public_id}/create-anyway"],
            ['post', "/integrations/meta/events/{$event->public_id}/dismiss"],
        ];

        foreach ([RoleName::SalesManager, RoleName::SalesAgent] as $role) {
            $user = $this->member($this->tenant, $role);
            foreach ($routes as [$method, $uri]) {
                $this->actingInTenant($user, $this->tenant)->{$method}($uri)->assertForbidden();
            }
        }

        $this->asGuest();
        foreach ($routes as [$method, $uri]) {
            $this->{$method}($uri)->assertRedirect('/login');
        }
        Http::assertNothingSent();
    }

    public function test_nobody_can_reach_another_tenants_meta_records(): void
    {
        [$other, $otherAdmin] = $this->provision('Globex');
        [$connection, $page, $mapping] = $this->connectMeta($other);
        $event = Tenancy::withoutScope(fn () => MetaLeadEvent::query()->create(['tenant_id' => $other->id, 'page_id' => '1', 'leadgen_id' => '2', 'status' => 'failed', 'received_at' => now()]));

        $this->asAdmin();
        $this->post("/integrations/meta/connections/{$connection->public_id}/validate")->assertNotFound();
        $this->delete("/integrations/meta/connections/{$connection->public_id}")->assertNotFound();
        $this->post("/integrations/meta/pages/{$page->public_id}/sync-forms")->assertNotFound();
        $this->delete("/integrations/meta/pages/{$page->public_id}")->assertNotFound();
        $this->put("/integrations/meta/mappings/{$mapping->public_id}", ['is_enabled' => false])->assertNotFound();
        $this->post("/integrations/meta/events/{$event->public_id}/retry")->assertNotFound();
        $this->post("/integrations/meta/events/{$event->public_id}/dismiss")->assertNotFound();

        $this->assertSame(MetaEventStatus::Failed, Tenancy::withoutScope(fn () => $event->fresh())->status);
        $this->assertTrue(Tenancy::withoutScope(fn () => MetaPage::query()->whereKey($page->id)->exists()));
    }

    public function test_index_exposes_only_this_tenants_data_and_no_secrets(): void
    {
        [$other, $otherAdmin] = $this->provision('Globex');
        $this->connectMeta($other, pageId: '100000000000009');
        [$connection] = $this->connectMeta($this->tenant);
        Tenancy::runAs($this->tenant, fn () => $connection->update(['pending_assets' => [['id' => '5', 'name' => 'Pending Page', 'access_token' => 'PENDING-SECRET']], 'pending_assets_expires_at' => now()->addMinutes(5)]));

        $response = $this->asAdmin()->get('/integrations/meta')->assertOk();
        $json = $response->getContent();

        $this->assertStringContainsString('Pending Page', $json);
        $this->assertStringNotContainsString('PENDING-SECRET', $json);
        $this->assertStringNotContainsString('PAGE-TOKEN', $json);
        $this->assertStringNotContainsString('100000000000009', $json);
        $this->assertStringNotContainsString($this->metaSecret, $json);
        $this->assertStringNotContainsString('test-verify-token', $json);
    }

    // ---- OAuth -------------------------------------------------------------------------------------------------

    public function test_connect_redirects_to_meta_with_a_server_side_redirect_uri_and_state(): void
    {
        $response = $this->asAdmin()->post('/integrations/meta/connect', [], ['X-Inertia' => 'true']);

        $response->assertStatus(409);
        $location = $response->headers->get('X-Inertia-Location');
        $this->assertStringStartsWith('https://www.example.test/v99.0/dialog/oauth?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $q);
        $this->assertSame('https://crm.example.test/integrations/meta/callback', $q['redirect_uri']);
        $this->assertSame('123456789', $q['client_id']);
        $this->assertNotEmpty($q['state']);
        $this->assertStringNotContainsString($this->metaSecret, $location);
        $this->assertSame(1, Tenancy::runAs($this->tenant, fn () => MetaOauthState::query()->count()));
    }

    public function test_connect_ignores_browser_supplied_redirect_uri_and_tenant(): void
    {
        $response = $this->asAdmin()->post('/integrations/meta/connect', ['redirect_uri' => 'https://evil.test/x', 'tenant_id' => 999], ['X-Inertia' => 'true']);

        parse_str(parse_url($response->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $q);
        $this->assertSame('https://crm.example.test/integrations/meta/callback', $q['redirect_uri']);
    }

    public function test_connect_without_configuration_shows_a_message_and_never_redirects(): void
    {
        config(['meta.app_id' => '', 'meta.app_secret' => '']);

        $this->asAdmin()->post('/integrations/meta/connect')->assertRedirect()->assertSessionHas('status');
        $this->assertSame(0, Tenancy::runAs($this->tenant, fn () => MetaOauthState::query()->count()));
    }

    public function test_callback_completes_the_flow_and_stores_only_encrypted_page_assets(): void
    {
        $state = $this->startFlow();
        $this->fakeOAuth();

        $this->get('/integrations/meta/callback?code=AUTHCODE&state='.urlencode($state))->assertRedirect('/integrations/meta');

        $connection = Tenancy::runAs($this->tenant, fn () => MetaConnection::query()->sole());
        $this->assertSame(MetaConnectionStatus::PendingSelection, $connection->status);
        $this->assertSame('u1', $connection->meta_user_id);
        $this->assertSame($this->admin->id, $connection->created_by);
        $raw = \DB::table('meta_connections')->value('pending_assets');
        $this->assertStringNotContainsString('PAGE-TOKEN-A', $raw);
        $this->assertStringNotContainsString('USER-TOKEN-LONG', json_encode(\DB::table('meta_connections')->get()));
        $this->assertSame(0, Tenancy::runAs($this->tenant, fn () => MetaPage::query()->count()));
        // The one-time code never goes into the audit log or anywhere persistent.
        $this->assertStringNotContainsString('AUTHCODE', json_encode(AuditLog::withoutGlobalScopes()->get()));
    }

    public function test_callback_rejects_missing_forged_replayed_and_expired_state(): void
    {
        $this->fakeOAuth();
        $this->asAdmin();

        $this->get('/integrations/meta/callback?code=c')->assertRedirect('/integrations/meta')->assertSessionHas('status');
        $this->get('/integrations/meta/callback?code=c&state=forged.value')->assertRedirect('/integrations/meta');

        $state = $this->startFlow();
        $this->get('/integrations/meta/callback?code=c&state='.urlencode($state))->assertSessionHas('status');
        $this->assertSame(1, Tenancy::runAs($this->tenant, fn () => MetaConnection::query()->count()));

        // Replay of the same (now used) state.
        $this->startFlow(); // new nonce in session, so only the single-use flag can stop the replay
        $this->get('/integrations/meta/callback?code=c&state='.urlencode($state))->assertRedirect('/integrations/meta');
        $this->assertSame(1, Tenancy::runAs($this->tenant, fn () => MetaConnection::query()->count()));

        // Expired.
        $expired = $this->startFlow();
        $this->travel(11)->minutes();
        $this->get('/integrations/meta/callback?code=c&state='.urlencode($expired))->assertSessionHas('status');
        $this->assertSame(1, Tenancy::runAs($this->tenant, fn () => MetaConnection::query()->count()));
    }

    public function test_state_is_bound_to_the_user_and_cannot_be_used_by_another_admin(): void
    {
        $state = $this->startFlow();
        $this->fakeOAuth();
        $second = $this->member($this->tenant, RoleName::Admin);

        $this->actingInTenant($second, $this->tenant)->get('/integrations/meta/callback?code=c&state='.urlencode($state))->assertRedirect('/integrations/meta');

        $this->assertSame(0, Tenancy::runAs($this->tenant, fn () => MetaConnection::query()->count()));
    }

    public function test_state_is_bound_to_the_tenant(): void
    {
        $state = $this->startFlow();
        $this->fakeOAuth();
        [$other] = $this->provision('Globex');
        $this->addMembership($other, $this->admin, RoleName::Admin);

        // Same user, same state, different workspace.
        $this->actingInTenant($this->admin, $other)->get('/integrations/meta/callback?code=c&state='.urlencode($state))->assertRedirect('/integrations/meta');

        $this->assertSame(0, Tenancy::runAs($other, fn () => MetaConnection::query()->count()));
        $this->assertSame(0, Tenancy::runAs($this->tenant, fn () => MetaConnection::query()->count()));
    }

    public function test_state_is_bound_to_the_browser_session(): void
    {
        $state = $this->startFlow();
        $this->fakeOAuth();

        // A fresh session (attacker tricks the admin into opening a link with the attacker's state).
        $this->actingInTenant($this->admin, $this->tenant)->get('/integrations/meta/callback?code=c&state='.urlencode($state))->assertRedirect('/integrations/meta');

        $this->assertSame(0, Tenancy::runAs($this->tenant, fn () => MetaConnection::query()->count()));
    }

    public function test_callback_with_error_param_creates_nothing_and_calls_no_api(): void
    {
        $this->startFlow();
        Http::fake();

        $this->get('/integrations/meta/callback?error=access_denied&error_reason=user_denied')->assertRedirect('/integrations/meta')->assertSessionHas('status');

        Http::assertNothingSent();
        $this->assertSame(0, Tenancy::runAs($this->tenant, fn () => MetaConnection::query()->count()));
    }

    public function test_missing_permissions_are_refused_and_name_the_missing_ones(): void
    {
        $state = $this->startFlow();
        $this->fakeOAuth(scopes: ['pages_show_list']);

        $response = $this->get('/integrations/meta/callback?code=c&state='.urlencode($state));

        $response->assertSessionHas('status', fn ($m) => str_contains($m, 'leads_retrieval'));
        $this->assertSame(0, Tenancy::runAs($this->tenant, fn () => MetaConnection::query()->count()));
    }

    public function test_api_failure_during_callback_shows_a_sanitized_message(): void
    {
        $state = $this->startFlow();
        Http::fake(['*' => Http::response(['error' => ['code' => 100, 'message' => 'Invalid code EAAB'.str_repeat('z', 60)]], 400)]);

        $response = $this->get('/integrations/meta/callback?code=c&state='.urlencode($state));

        $response->assertRedirect('/integrations/meta');
        $this->assertStringNotContainsString('EAAB', (string) session('status'));
    }

    // ---- pages / mapping ---------------------------------------------------------------------------------------

    private function connected(): MetaConnection
    {
        $state = $this->startFlow();
        $this->fakeOAuth();
        $this->get('/integrations/meta/callback?code=c&state='.urlencode($state));

        return Tenancy::runAs($this->tenant, fn () => MetaConnection::query()->sole());
    }

    public function test_selecting_pages_uses_only_server_side_assets_and_subscribes(): void
    {
        $connection = $this->connected();

        $this->post("/integrations/meta/connections/{$connection->public_id}/pages", [
            'pages' => ['100000000000001', '555'], // 555 was never offered
            'access_token' => 'INJECTED',
        ])->assertRedirect();

        $pages = Tenancy::runAs($this->tenant, fn () => MetaPage::query()->get());
        $this->assertCount(1, $pages);
        $this->assertSame('PAGE-TOKEN-A', $pages->first()->access_token);
        $this->assertSame('subscribed', $pages->first()->subscription_status);
        $this->assertSame(MetaConnectionStatus::Active, Tenancy::runAs($this->tenant, fn () => $connection->fresh()->status));
        $this->assertNull(\DB::table('meta_connections')->value('pending_assets'));
        $form = Tenancy::runAs($this->tenant, fn () => MetaFormMapping::query()->sole());
        $this->assertFalse($form->is_enabled);
        $this->assertNull($form->lead_source_id);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/100000000000001/subscribed_apps') && $r->method() === 'POST' && ! str_contains($r->url(), 'PAGE-TOKEN'));
    }

    public function test_a_page_already_connected_by_another_workspace_is_not_offered_or_stolen(): void
    {
        [$other] = $this->provision('Globex');
        $this->connectMeta($other, pageId: '100000000000001');
        $connection = $this->connected();

        $this->post("/integrations/meta/connections/{$connection->public_id}/pages", ['pages' => ['100000000000001']])->assertRedirect();

        $this->assertSame(0, Tenancy::runAs($this->tenant, fn () => MetaPage::query()->count()));
        $this->assertSame($other->id, MetaPage::withoutGlobalScopes()->where('page_id', '100000000000001')->value('tenant_id'));
    }

    public function test_pending_page_list_expires(): void
    {
        $connection = $this->connected();
        $this->travel(31)->minutes();

        $this->post("/integrations/meta/connections/{$connection->public_id}/pages", ['pages' => ['100000000000001']])->assertSessionHas('status');

        $this->assertSame(0, Tenancy::runAs($this->tenant, fn () => MetaPage::query()->count()));
    }

    public function test_subscription_failure_is_recorded_not_fatal(): void
    {
        $connection = $this->connected();
        Http::swap(new Factory);
        Http::fake(['*/subscribed_apps*' => Http::response(['error' => ['code' => 200]], 403), '*/leadgen_forms*' => Http::response(['data' => []])]);

        $this->post("/integrations/meta/connections/{$connection->public_id}/pages", ['pages' => ['100000000000001']]);

        $page = Tenancy::runAs($this->tenant, fn () => MetaPage::query()->sole());
        $this->assertSame('failed', $page->subscription_status);
        $this->assertNotNull($page->subscription_error);
    }

    public function test_mapping_validates_same_tenant_references(): void
    {
        [, , $mapping] = $this->connectMeta($this->tenant);
        [$other, $otherAdmin] = $this->provision('Globex');
        $this->asAdmin();
        $url = "/integrations/meta/mappings/{$mapping->public_id}";
        $good = ['is_enabled' => true, 'lead_source_id' => $this->sourceId($this->tenant), 'pipeline_stage_id' => $this->stageId($this->tenant, 'new')];

        $this->put($url, array_merge($good, ['lead_source_id' => $this->sourceId($other)]))->assertSessionHasErrors('lead_source_id');
        $this->put($url, array_merge($good, ['pipeline_stage_id' => $this->stageId($other, 'new')]))->assertSessionHasErrors('pipeline_stage_id');
        $this->put($url, array_merge($good, ['default_owner_id' => $otherAdmin->id]))->assertSessionHasErrors('default_owner_id');

        $suspended = $this->member($this->tenant, RoleName::SalesAgent, MembershipStatus::Suspended);
        $this->put($url, array_merge($good, ['default_owner_id' => $suspended->id]))->assertSessionHasErrors('default_owner_id');

        $this->put($url, array_merge($good, ['lead_source_id' => null]))->assertSessionHasErrors();

        $agent = $this->member($this->tenant, RoleName::SalesAgent);
        $this->put($url, array_merge($good, ['default_owner_id' => $agent->id]))->assertSessionHasNoErrors();
        $saved = Tenancy::runAs($this->tenant, fn () => $mapping->fresh());
        $this->assertTrue($saved->is_enabled);
        $this->assertSame($agent->id, $saved->default_owner_id);
    }

    public function test_database_rejects_cross_tenant_mapping_references_even_if_validation_is_bypassed(): void
    {
        [, , $mapping] = $this->connectMeta($this->tenant);
        [$other] = $this->provision('Globex');

        $this->expectException(QueryException::class);
        \DB::table('meta_form_mappings')->where('id', $mapping->id)->update(['lead_source_id' => $this->sourceId($other)]);
    }

    public function test_database_rejects_enabling_an_incomplete_mapping(): void
    {
        [, , $mapping] = $this->connectMeta($this->tenant, enabled: false);

        $this->expectException(QueryException::class);
        \DB::table('meta_form_mappings')->where('id', $mapping->id)->update(['is_enabled' => 1, 'lead_source_id' => null]);
    }

    public function test_database_rejects_a_page_pointing_at_another_tenants_connection(): void
    {
        [$connection] = $this->connectMeta($this->tenant);
        [$other] = $this->provision('Globex');

        $this->expectException(QueryException::class);
        \DB::table('meta_pages')->insert(['tenant_id' => $other->id, 'meta_connection_id' => $connection->id, 'public_id' => (string) Str::ulid(), 'page_id' => '42', 'name' => 'x', 'access_token' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---- disconnect / health -----------------------------------------------------------------------------------

    public function test_disconnect_deletes_tokens_even_when_meta_is_down_and_keeps_leads(): void
    {
        [$connection, $page] = $this->connectMeta($this->tenant);
        $lead = $this->lead($this->tenant, ['external_provider' => 'meta', 'external_id' => '1']);
        Http::fake(['*' => Http::response('', 500)]);

        $this->asAdmin()->delete("/integrations/meta/connections/{$connection->public_id}")->assertRedirect('/integrations/meta');

        $this->assertSame(0, \DB::table('meta_pages')->count());
        $this->assertSame(0, \DB::table('meta_form_mappings')->count());
        $this->assertSame(MetaConnectionStatus::Disconnected, Tenancy::runAs($this->tenant, fn () => $connection->fresh()->status));
        $this->assertTrue(Tenancy::runAs($this->tenant, fn () => $lead->fresh()->exists));
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/subscribed_apps'));
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'meta.connection.disconnected')->exists());
    }

    public function test_events_for_a_disconnected_page_are_quarantined_not_attributed(): void
    {
        [$connection] = $this->connectMeta($this->tenant);
        Http::fake(['*' => Http::response('', 200)]);
        $this->asAdmin()->delete("/integrations/meta/connections/{$connection->public_id}");

        $this->postWebhook($this->fixture('webhook_leadgen'))->assertOk();

        $this->assertSame(MetaEventStatus::Quarantined, $this->eventFor($this->tenant)->status);
        $this->assertNull($this->eventFor($this->tenant)->tenant_id);
    }

    public function test_validate_flags_reauthorization_when_token_is_invalid(): void
    {
        [$connection] = $this->connectMeta($this->tenant);
        Http::fake(['*/debug_token*' => Http::response(['data' => ['is_valid' => false]])]);

        $this->asAdmin()->post("/integrations/meta/connections/{$connection->public_id}/validate");

        $this->assertSame(MetaConnectionStatus::ReauthorizationRequired, Tenancy::runAs($this->tenant, fn () => $connection->fresh()->status));
    }

    public function test_validate_keeps_status_on_transient_errors_and_recovers_on_success(): void
    {
        [$connection] = $this->connectMeta($this->tenant);
        Http::fake(['*/debug_token*' => Http::sequence()->push('', 503)->push(['data' => ['is_valid' => true, 'scopes' => config('meta.oauth_scopes')]])]);
        $this->asAdmin()->post("/integrations/meta/connections/{$connection->public_id}/validate");
        $this->assertSame(MetaConnectionStatus::Active, Tenancy::runAs($this->tenant, fn () => $connection->fresh()->status));

        Tenancy::runAs($this->tenant, fn () => $connection->update(['status' => MetaConnectionStatus::ReauthorizationRequired]));
        $this->post("/integrations/meta/connections/{$connection->public_id}/validate");
        $this->assertSame(MetaConnectionStatus::Active, Tenancy::runAs($this->tenant, fn () => $connection->fresh()->status));
    }

    // ---- event actions -----------------------------------------------------------------------------------------

    public function test_retry_dismiss_and_create_anyway_are_guarded_transitions(): void
    {
        $make = fn (string $status, string $leadgen) => Tenancy::withoutScope(fn () => MetaLeadEvent::query()->create(['tenant_id' => $this->tenant->id, 'page_id' => '1', 'leadgen_id' => $leadgen, 'status' => $status, 'received_at' => now()]));
        $failed = $make('failed', '1');
        $review = $make('needs_review', '2');
        $imported = $make('imported', '3');
        $fresh = fn ($e) => Tenancy::withoutScope(fn () => $e->fresh());
        $this->asAdmin();

        $this->post("/integrations/meta/events/{$failed->public_id}/retry");
        $this->assertSame(MetaEventStatus::Received, $fresh($failed)->status);

        $this->post("/integrations/meta/events/{$imported->public_id}/retry")->assertSessionHas('status');
        $this->assertSame(MetaEventStatus::Imported, $fresh($imported)->status);
        $this->post("/integrations/meta/events/{$failed->public_id}/dismiss");
        $this->assertSame(MetaEventStatus::Received, $fresh($failed)->status);

        $this->post("/integrations/meta/events/{$review->public_id}/create-anyway");
        $this->assertTrue($fresh($review)->confirm_duplicate);
        $this->assertSame(MetaEventStatus::Received, $fresh($review)->status);
    }

    public function test_dismiss_closes_a_flagged_duplicate_without_creating_a_lead(): void
    {
        $review = Tenancy::withoutScope(fn () => MetaLeadEvent::query()->create(['tenant_id' => $this->tenant->id, 'page_id' => '1', 'leadgen_id' => '2', 'status' => 'needs_review', 'received_at' => now()]));

        $this->asAdmin()->post("/integrations/meta/events/{$review->public_id}/dismiss");

        $this->assertSame(MetaEventStatus::Dismissed, Tenancy::withoutScope(fn () => $review->fresh())->status);
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'meta.event.dismissed')->exists());
    }
}
