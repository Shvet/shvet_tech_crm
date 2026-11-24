<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\RoleName;
use App\Enums\WhatsAppAccountStatus;
use App\Models\Lead;
use App\Models\TenantMembership;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Support\Facades\Tenancy;
use Illuminate\Support\Collection;
use Tests\Support\WhatsAppTestHelpers;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use WhatsAppTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureWhatsApp();
    }

    private function messages(): Collection
    {
        return Tenancy::withoutScope(fn () => WhatsAppMessage::query()->orderBy('id')->get());
    }

    // ---- handshake & signature ---------------------------------------------------------------------------------

    public function test_handshake_echoes_challenge_only_with_the_whatsapp_verify_token(): void
    {
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wa-verify-token&hub.challenge=4242')->assertOk()->assertSee('4242', false);
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=4242')->assertForbidden();
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wa-verify-token&hub.challenge=%3Cb%3E')->assertForbidden();
        config(['whatsapp.webhook_verify_token' => '']);
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=&hub.challenge=4242')->assertForbidden();
    }

    public function test_bad_signature_is_rejected_and_nothing_is_stored(): void
    {
        [$tenant] = $this->provision('Acme');
        $this->connectWhatsApp($tenant);

        $this->postWhatsApp($this->waPayload([$this->textMessage()]), 'sha256='.str_repeat('0', 64))->assertForbidden();
        $this->postWhatsApp($this->waPayload([$this->textMessage()]), '')->assertForbidden();

        $this->assertCount(0, $this->messages());
    }

    public function test_missing_app_secret_fails_closed(): void
    {
        config(['meta.app_secret' => '']);
        $this->postWhatsApp($this->waPayload([$this->textMessage()]), 'sha256='.hash_hmac('sha256', json_encode($this->waPayload([$this->textMessage()])), ''))->assertStatus(503);
    }

    public function test_oversized_and_malformed_bodies_are_rejected(): void
    {
        config(['whatsapp.webhook_max_bytes' => 100]);
        $this->postWhatsApp($this->waPayload([$this->textMessage()]))->assertStatus(413);

        config(['whatsapp.webhook_max_bytes' => 262144]);
        $this->postWhatsApp('not json')->assertStatus(400);
    }

    // ---- inbound messages --------------------------------------------------------------------------------------

    public function test_inbound_text_creates_contact_conversation_message_and_a_whatsapp_lead(): void
    {
        [$tenant] = $this->provision('Acme');
        $this->connectWhatsApp($tenant);

        $this->postWhatsApp($this->waPayload([$this->textMessage()]))->assertOk()->assertSee('EVENT_RECEIVED');

        $this->runIn($tenant, function () use ($tenant) {
            $contact = WhatsAppContact::query()->sole();
            $this->assertSame('919876543210', $contact->wa_id);
            $this->assertSame('Ravi Kumar', $contact->profile_name);

            $conversation = WhatsAppConversation::query()->sole();
            $this->assertSame(1, $conversation->unread_count);
            $this->assertTrue($conversation->serviceWindowOpen());
            $this->assertSame(WhatsAppConversation::LINKED, $conversation->link_status);

            $lead = Lead::query()->sole();
            $this->assertSame('Ravi Kumar', $lead->full_name);
            $this->assertSame('+919876543210', $lead->phone_e164);
            $this->assertSame('whatsapp', $lead->external_provider);
            $this->assertSame($this->sourceId($tenant, 'whatsapp'), (int) $lead->lead_source_id);
            $this->assertNull($lead->owner_id);
            $this->assertSame($lead->id, $conversation->lead_id);

            $message = WhatsAppMessage::query()->sole();
            $this->assertSame('in', $message->direction);
            $this->assertSame('Hi, I saw your ad', $message->body);
        });
    }

    public function test_message_body_is_encrypted_at_rest(): void
    {
        [$tenant] = $this->provision('Acme');
        $this->connectWhatsApp($tenant);
        $this->postWhatsApp($this->waPayload([$this->textMessage(body: 'secret plan details')]))->assertOk();

        $raw = \DB::table('whatsapp_messages')->value('body');
        $this->assertStringNotContainsString('secret plan', (string) $raw);
    }

    public function test_redelivery_of_the_same_message_is_stored_once(): void
    {
        [$tenant] = $this->provision('Acme');
        $this->connectWhatsApp($tenant);

        $payload = $this->waPayload([$this->textMessage()]);
        $this->postWhatsApp($payload)->assertOk();
        $this->postWhatsApp($payload)->assertOk();

        $this->assertCount(1, $this->messages());
        $this->runIn($tenant, fn () => $this->assertSame(1, WhatsAppConversation::query()->sole()->unread_count));
        $this->runIn($tenant, fn () => $this->assertSame(1, Lead::query()->count()));
    }

    public function test_existing_lead_with_the_same_number_is_linked_not_duplicated(): void
    {
        [$tenant] = $this->provision('Acme');
        $agent = $this->member($tenant, RoleName::SalesAgent);
        $lead = $this->lead($tenant, ['phone_e164' => '+919876543210', 'owner_id' => $agent->id, 'full_name' => 'Ravi Existing']);
        $this->connectWhatsApp($tenant);

        $this->postWhatsApp($this->waPayload([$this->textMessage()]))->assertOk();

        $this->runIn($tenant, function () use ($lead) {
            $this->assertSame(1, Lead::query()->count());
            $conversation = WhatsAppConversation::query()->sole();
            $this->assertSame($lead->id, $conversation->lead_id);
            $this->assertSame('Ravi Existing', $lead->fresh()->full_name, 'existing leads are never modified');
        });
    }

    public function test_two_leads_sharing_the_number_leave_the_conversation_ambiguous(): void
    {
        [$tenant] = $this->provision('Acme');
        $this->lead($tenant, ['phone_e164' => '+919876543210']);
        $this->lead($tenant, ['phone_e164' => '+919876543210']);
        $this->connectWhatsApp($tenant);

        $this->postWhatsApp($this->waPayload([$this->textMessage()]))->assertOk();

        $this->runIn($tenant, function () {
            $conversation = WhatsAppConversation::query()->sole();
            $this->assertSame(WhatsAppConversation::AMBIGUOUS, $conversation->link_status);
            $this->assertNull($conversation->lead_id);
            $this->assertSame(2, Lead::query()->count());
        });
    }

    public function test_auto_create_off_leaves_the_conversation_unlinked(): void
    {
        [$tenant] = $this->provision('Acme');
        $this->connectWhatsApp($tenant, attributes: ['auto_create_leads' => false]);

        $this->postWhatsApp($this->waPayload([$this->textMessage()]))->assertOk();

        $this->runIn($tenant, function () {
            $this->assertSame(0, Lead::query()->count());
            $this->assertSame(WhatsAppConversation::UNLINKED, WhatsAppConversation::query()->sole()->link_status);
        });
    }

    public function test_default_owner_receives_new_leads_and_an_inactive_one_falls_back_to_unassigned(): void
    {
        [$tenant] = $this->provision('Acme');
        $agent = $this->member($tenant, RoleName::SalesAgent);
        $account = $this->connectWhatsApp($tenant, attributes: ['default_owner_id' => $agent->id]);

        $this->postWhatsApp($this->waPayload([$this->textMessage()]))->assertOk();
        $this->runIn($tenant, fn () => $this->assertSame($agent->id, (int) Lead::query()->sole()->owner_id));

        $this->runIn($tenant, fn () => TenantMembership::query()->where('user_id', $agent->id)->update(['status' => 'suspended']));
        $this->postWhatsApp($this->waPayload([$this->textMessage('wamid.second0000001', '919811112222')]))->assertOk();
        $this->runIn($tenant, fn () => $this->assertNull(Lead::query()->where('phone_e164', '+919811112222')->sole()->owner_id));
    }

    public function test_unknown_or_disconnected_number_is_ignored(): void
    {
        [$tenant] = $this->provision('Acme');
        $account = $this->connectWhatsApp($tenant);

        $this->postWhatsApp($this->waPayload([$this->textMessage()], phoneNumberId: '999999999'))->assertOk();
        $this->assertCount(0, $this->messages());

        $this->runIn($tenant, fn () => $account->update(['status' => WhatsAppAccountStatus::Disconnected]));
        $this->postWhatsApp($this->waPayload([$this->textMessage()]))->assertOk();
        $this->assertCount(0, $this->messages());
    }

    public function test_events_are_routed_to_the_tenant_that_owns_the_number_only(): void
    {
        [$acme] = $this->provision('Acme');
        [$beta] = $this->provision('Beta');
        $this->connectWhatsApp($acme, '110000000000001');
        $this->connectWhatsApp($beta, '110000000000002');

        $this->postWhatsApp($this->waPayload([$this->textMessage()], phoneNumberId: '110000000000002'))->assertOk();

        $this->runIn($acme, fn () => $this->assertSame(0, WhatsAppMessage::query()->count()));
        $this->runIn($beta, fn () => $this->assertSame(1, WhatsAppMessage::query()->count()));
    }

    public function test_malformed_items_are_skipped_without_failing_the_delivery(): void
    {
        [$tenant] = $this->provision('Acme');
        $this->connectWhatsApp($tenant);

        $payload = $this->waPayload([
            ['from' => 'not-a-number', 'id' => 'wamid.bad000000001', 'type' => 'text', 'text' => ['body' => 'x']],
            ['from' => '919876543210', 'id' => '<script>', 'type' => 'text', 'text' => ['body' => 'x']],
            $this->textMessage('wamid.good000000001'),
        ]);
        $this->postWhatsApp($payload)->assertOk();

        $this->assertSame(['wamid.good000000001'], $this->messages()->pluck('wamid')->all());
    }

    public function test_non_text_messages_are_stored_with_their_kind(): void
    {
        [$tenant] = $this->provision('Acme');
        $this->connectWhatsApp($tenant);

        $this->postWhatsApp($this->waPayload([
            ['from' => '919876543210', 'id' => 'wamid.image0000001', 'timestamp' => (string) now()->timestamp, 'type' => 'image', 'image' => ['id' => '1', 'caption' => 'Our site plan', 'mime_type' => 'image/jpeg']],
            ['from' => '919876543210', 'id' => 'wamid.loc000000001', 'timestamp' => (string) now()->timestamp, 'type' => 'location', 'location' => ['latitude' => 1, 'longitude' => 2]],
            ['from' => '919876543210', 'id' => 'wamid.weird0000001', 'timestamp' => (string) now()->timestamp, 'type' => 'something_new'],
        ]))->assertOk();

        $this->runIn($tenant, function () {
            $this->assertSame(['image' => 'Our site plan', 'location' => null, 'unsupported' => null], WhatsAppMessage::query()->orderBy('id')->get()->mapWithKeys(fn ($m) => [$m->kind => $m->body])->all());
        });
    }

    public function test_stop_opts_the_contact_out_and_start_opts_back_in(): void
    {
        [$tenant] = $this->provision('Acme');
        $this->connectWhatsApp($tenant);

        $this->postWhatsApp($this->waPayload([$this->textMessage('wamid.stop00000001', body: ' STOP ')]))->assertOk();
        $this->runIn($tenant, fn () => $this->assertTrue(WhatsAppContact::query()->sole()->isOptedOut()));

        $this->postWhatsApp($this->waPayload([$this->textMessage('wamid.start0000001', body: 'start')]))->assertOk();
        $this->runIn($tenant, fn () => $this->assertFalse(WhatsAppContact::query()->sole()->isOptedOut()));
    }

    // ---- statuses ----------------------------------------------------------------------------------------------

    public function test_statuses_move_forward_only_and_failed_is_terminal(): void
    {
        [$tenant] = $this->provision('Acme');
        $account = $this->connectWhatsApp($tenant);
        $conversation = $this->conversation($tenant, $account);
        $message = $this->runIn($tenant, fn () => WhatsAppMessage::query()->create([
            'whatsapp_conversation_id' => $conversation->id, 'direction' => 'out', 'wamid' => 'wamid.out000000001',
            'kind' => 'text', 'body' => 'Hi', 'status' => 'sent',
        ]));
        $status = fn (string $s, array $extra = []) => ['id' => 'wamid.out000000001', 'status' => $s, 'timestamp' => (string) now()->timestamp, 'recipient_id' => '919876543210'] + $extra;

        $this->postWhatsApp($this->waPayload(statuses: [$status('read')]))->assertOk();
        $this->postWhatsApp($this->waPayload(statuses: [$status('delivered')]))->assertOk(); // late, out of order
        $this->runIn($tenant, fn () => $this->assertSame('read', $message->fresh()->status->value));

        $this->postWhatsApp($this->waPayload(statuses: [$status('failed', ['errors' => [['code' => 131026, 'title' => "Message undeliverable\x07"]]])]))->assertOk();
        $this->postWhatsApp($this->waPayload(statuses: [$status('read')]))->assertOk();
        $this->runIn($tenant, function () use ($message) {
            $fresh = $message->fresh();
            $this->assertSame('failed', $fresh->status->value);
            $this->assertSame('wa_131026', $fresh->error_code);
            $this->assertSame('Message undeliverable ', $fresh->error_title);
        });
    }

    public function test_status_for_another_tenants_message_cannot_touch_it(): void
    {
        [$acme] = $this->provision('Acme');
        [$beta] = $this->provision('Beta');
        $acmeAccount = $this->connectWhatsApp($acme, '110000000000001');
        $this->connectWhatsApp($beta, '110000000000002');
        $conversation = $this->conversation($acme, $acmeAccount);
        $message = $this->runIn($acme, fn () => WhatsAppMessage::query()->create([
            'whatsapp_conversation_id' => $conversation->id, 'direction' => 'out', 'wamid' => 'wamid.acme00000001', 'kind' => 'text', 'body' => 'Hi', 'status' => 'sent',
        ]));

        // Delivered on Beta's number, naming Acme's wamid: Beta's tenant scope cannot see it.
        $this->postWhatsApp($this->waPayload(statuses: [['id' => 'wamid.acme00000001', 'status' => 'read', 'timestamp' => (string) now()->timestamp]], phoneNumberId: '110000000000002'))->assertOk();

        $this->runIn($acme, fn () => $this->assertSame('sent', $message->fresh()->status->value));
    }
}
