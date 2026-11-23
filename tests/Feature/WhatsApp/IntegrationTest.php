<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\RoleName;
use App\Enums\WhatsAppAccountStatus;
use App\Models\AuditLog;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use App\Support\Facades\Tenancy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\WhatsAppTestHelpers;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    use WhatsAppTestHelpers;

    private const TOKEN = 'EAAG-system-user-token-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureWhatsApp();
    }

    private function fakeMeta(array $ownedIds = ['110000000000001'], int $phoneStatus = 200): void
    {
        Http::fake([
            'graph.example.test/v99.0/110000000000001?*' => Http::response($phoneStatus === 200 ? ['display_phone_number' => '+91 22 4000 0000', 'verified_name' => 'Acme Realty', 'id' => '110000000000001'] : ['error' => ['code' => 190]], $phoneStatus),
            'graph.example.test/v99.0/220000000000001/phone_numbers*' => Http::response(['data' => array_map(fn ($id) => ['id' => $id], $ownedIds)]),
            'graph.example.test/v99.0/220000000000001/subscribed_apps*' => Http::response(['success' => true]),
            'graph.example.test/v99.0/220000000000001/message_templates*' => Http::response(['data' => [
                ['name' => 'follow_up', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'MARKETING', 'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, about {{2}} and {{1}}']]],
                ['name' => 'draft', 'language' => 'hi', 'status' => 'PENDING', 'category' => 'UTILITY', 'components' => []],
            ]]),
        ]);
    }

    private function connectPayload(array $overrides = []): array
    {
        return $overrides + ['waba_id' => '220000000000001', 'phone_number_id' => '110000000000001', 'access_token' => self::TOKEN, 'auto_create_leads' => true];
    }

    public function test_admin_connects_a_number_and_templates_are_synced(): void
    {
        $this->fakeMeta();
        [$tenant, $admin] = $this->provision('Acme');

        $this->actingInTenant($admin, $tenant)->post('/integrations/whatsapp', $this->connectPayload())->assertSessionHasNoErrors();

        $this->runIn($tenant, function () {
            $account = WhatsAppAccount::query()->sole();
            $this->assertSame(WhatsAppAccountStatus::Active, $account->status);
            $this->assertSame('Acme Realty', $account->verified_name);
            $this->assertSame('subscribed', $account->subscription_status);
            $this->assertSame(self::TOKEN, $account->access_token);
            $this->assertSame([2, 0], WhatsAppTemplate::query()->orderBy('name')->pluck('body_param_count')->reverse()->values()->all());
            $this->assertTrue(AuditLog::query()->where('action', 'whatsapp.account.connected')->exists());
        });
        $this->assertStringNotContainsString(self::TOKEN, (string) \DB::table('whatsapp_accounts')->value('access_token'));
        Http::assertSent(fn (Request $r) => ! str_contains($r->url(), self::TOKEN));
    }

    public function test_number_not_in_the_business_account_is_refused(): void
    {
        $this->fakeMeta(ownedIds: ['110000000000777']);
        [$tenant, $admin] = $this->provision('Acme');

        $this->actingInTenant($admin, $tenant)->post('/integrations/whatsapp', $this->connectPayload())->assertSessionHasErrors('phone_number_id');
        $this->assertSame(0, Tenancy::withoutScope(fn () => WhatsAppAccount::query()->count()));
    }

    public function test_rejected_token_is_refused_with_a_readable_error(): void
    {
        $this->fakeMeta(phoneStatus: 401);
        [$tenant, $admin] = $this->provision('Acme');

        $this->actingInTenant($admin, $tenant)->post('/integrations/whatsapp', $this->connectPayload())->assertSessionHasErrors(['access_token' => 'Meta rejected this access token (invalid or expired).']);
    }

    public function test_a_number_connected_in_another_workspace_cannot_be_claimed(): void
    {
        $this->fakeMeta();
        [$acme] = $this->provision('Acme');
        [$beta, $betaAdmin] = $this->provision('Beta');
        $this->connectWhatsApp($acme, '110000000000001');

        $this->actingInTenant($betaAdmin, $beta)->post('/integrations/whatsapp', $this->connectPayload())->assertSessionHasErrors('phone_number_id');
        Http::assertNothingSent();
    }

    public function test_input_is_validated_before_calling_meta(): void
    {
        Http::fake();
        [$tenant, $admin] = $this->provision('Acme');

        $this->actingInTenant($admin, $tenant)->post('/integrations/whatsapp', $this->connectPayload(['waba_id' => '../me', 'phone_number_id' => '1/messages', 'access_token' => 'short']))
            ->assertSessionHasErrors(['waba_id', 'phone_number_id', 'access_token']);
        Http::assertNothingSent();
    }

    public function test_only_admins_manage_the_integration(): void
    {
        [$tenant] = $this->provision('Acme');
        $manager = $this->member($tenant, RoleName::SalesManager);
        $account = $this->connectWhatsApp($tenant);

        $this->actingInTenant($manager, $tenant)->get('/integrations/whatsapp')->assertForbidden();
        $this->actingInTenant($manager, $tenant)->post('/integrations/whatsapp', $this->connectPayload())->assertForbidden();
        $this->actingInTenant($manager, $tenant)->delete("/integrations/whatsapp/{$account->public_id}")->assertForbidden();
    }

    public function test_settings_disconnect_and_index(): void
    {
        [$tenant, $admin] = $this->provision('Acme');
        $agent = $this->member($tenant, RoleName::SalesAgent);
        $account = $this->connectWhatsApp($tenant);
        $this->template($tenant, $account);

        $this->actingInTenant($admin, $tenant)->get('/integrations/whatsapp')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Integrations/WhatsApp/Index')->where('account.verified_name', 'Test Business')->has('templates', 1)->missing('account.access_token'));

        $this->actingInTenant($admin, $tenant)->patch("/integrations/whatsapp/{$account->public_id}", ['auto_create_leads' => false, 'default_owner_id' => $agent->id])->assertSessionHasNoErrors();
        $this->runIn($tenant, fn () => $this->assertSame([false, $agent->id], [$account->fresh()->auto_create_leads, (int) $account->fresh()->default_owner_id]));

        $this->actingInTenant($admin, $tenant)->delete("/integrations/whatsapp/{$account->public_id}")->assertSessionHasNoErrors();
        $this->runIn($tenant, function () use ($account) {
            $fresh = $account->fresh();
            $this->assertSame(WhatsAppAccountStatus::Disconnected, $fresh->status);
            $this->assertNull($fresh->access_token);
            $this->assertSame(0, WhatsAppTemplate::query()->count());
        });
    }

    public function test_foreign_account_ids_are_404(): void
    {
        [$acme] = $this->provision('Acme');
        [$beta, $betaAdmin] = $this->provision('Beta');
        $account = $this->connectWhatsApp($acme);

        $this->actingInTenant($betaAdmin, $beta)->delete("/integrations/whatsapp/{$account->public_id}")->assertNotFound();
        $this->assertSame(WhatsAppAccountStatus::Active, $this->runIn($acme, fn () => $account->fresh()->status));
    }

    public function test_validate_flags_a_revoked_token(): void
    {
        Http::fake(['graph.example.test/*' => Http::response(['error' => ['code' => 190]], 401)]);
        [$tenant, $admin] = $this->provision('Acme');
        $account = $this->connectWhatsApp($tenant);

        $this->actingInTenant($admin, $tenant)->post("/integrations/whatsapp/{$account->public_id}/validate")->assertRedirect();
        $this->assertSame(WhatsAppAccountStatus::ReauthorizationRequired, $this->runIn($tenant, fn () => $account->fresh()->status));
    }
}
