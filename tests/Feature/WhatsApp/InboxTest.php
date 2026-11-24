<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\RoleName;
use App\Models\WhatsAppConversation;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\WhatsAppTestHelpers;
use Tests\TestCase;

class InboxTest extends TestCase
{
    use WhatsAppTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureWhatsApp();
    }

    public function test_managers_see_every_conversation_and_agents_only_their_leads(): void
    {
        [$tenant, $admin] = $this->provision('Acme');
        $manager = $this->member($tenant, RoleName::SalesManager);
        $agent = $this->member($tenant, RoleName::SalesAgent);
        $account = $this->connectWhatsApp($tenant);
        $mine = $this->conversation($tenant, $account, '919800000001', $this->lead($tenant, ['owner_id' => $agent->id])->id);
        $this->conversation($tenant, $account, '919800000002', $this->lead($tenant)->id);
        $this->conversation($tenant, $account, '919800000003');

        foreach ([$admin, $manager] as $user) {
            $this->actingInTenant($user, $tenant)->get('/inbox')->assertOk()
                ->assertInertia(fn (Assert $p) => $p->component('Inbox/Index')->has('conversations', 3));
        }

        $this->actingInTenant($agent, $tenant)->get('/inbox')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('conversations', 1)->where('conversations.0.id', $mine->public_id));
    }

    public function test_opening_a_conversation_shows_messages_and_marks_it_read(): void
    {
        [$tenant, $admin] = $this->provision('Acme');
        $account = $this->connectWhatsApp($tenant);
        $conversation = $this->conversation($tenant, $account);
        $this->runIn($tenant, fn () => $conversation->update(['unread_count' => 3]));
        $this->template($tenant, $account);
        $this->template($tenant, $account, 'not_yet', 'PENDING');

        $this->actingInTenant($admin, $tenant)->get("/inbox/{$conversation->public_id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('conversation.id', $conversation->public_id)
                ->where('conversation.window_open', true)
                ->where('conversation.messages.0.body', 'Hello there')
                ->has('conversation.templates', 1)
                ->where('conversation.can.reply', true)
                ->missing('conversation.messages.0.wamid'));

        $this->assertSame(0, $this->runIn($tenant, fn () => $conversation->fresh()->unread_count));
    }

    public function test_props_never_contain_the_access_token(): void
    {
        [$tenant, $admin] = $this->provision('Acme');
        $account = $this->connectWhatsApp($tenant);
        $conversation = $this->conversation($tenant, $account);

        foreach (['/inbox', "/inbox/{$conversation->public_id}", '/integrations/whatsapp'] as $url) {
            $this->assertStringNotContainsString('WA-TOKEN', $this->actingInTenant($admin, $tenant)->get($url)->assertOk()->getContent(), $url);
        }
    }

    public function test_search_and_filters(): void
    {
        [$tenant, $admin] = $this->provision('Acme');
        $account = $this->connectWhatsApp($tenant);
        $this->conversation($tenant, $account, '919800000001', $this->lead($tenant, ['full_name' => 'Meera Shah'])->id);
        $unread = $this->conversation($tenant, $account, '919811112222');
        $this->runIn($tenant, fn () => $unread->update(['unread_count' => 2]));

        $get = fn (string $q) => $this->actingInTenant($admin, $tenant)->get('/inbox?'.$q)->assertOk();
        $get('q=meera')->assertInertia(fn (Assert $p) => $p->has('conversations', 1)->where('conversations.0.name', 'Meera Shah'));
        $get('q=1111')->assertInertia(fn (Assert $p) => $p->has('conversations', 1)->where('conversations.0.id', $unread->public_id));
        $get('filter=unread')->assertInertia(fn (Assert $p) => $p->has('conversations', 1));
        $get('filter=unlinked')->assertInertia(fn (Assert $p) => $p->has('conversations', 1)->where('conversations.0.id', $unread->public_id));
        $get('q=%25')->assertInertia(fn (Assert $p) => $p->has('conversations', 0));
    }

    public function test_conversations_of_other_tenants_and_other_agents_are_404(): void
    {
        [$acme] = $this->provision('Acme');
        [$beta, $betaAdmin] = $this->provision('Beta');
        $foreign = $this->conversation($acme, $this->connectWhatsApp($acme));
        $agent = $this->member($beta, RoleName::SalesAgent);
        $betaAccount = $this->connectWhatsApp($beta, '110000000000002');
        $notMine = $this->conversation($beta, $betaAccount, '919800000009', $this->lead($beta)->id);

        $this->actingInTenant($betaAdmin, $beta)->get("/inbox/{$foreign->public_id}")->assertNotFound();
        $this->actingInTenant($betaAdmin, $beta)->post("/inbox/{$foreign->public_id}/messages", ['type' => 'text', 'body' => 'x'])->assertNotFound();
        $this->actingInTenant($agent, $beta)->get("/inbox/{$notMine->public_id}")->assertNotFound();
    }

    public function test_manager_links_an_ambiguous_conversation_to_a_lead_and_agent_cannot(): void
    {
        [$tenant] = $this->provision('Acme');
        $manager = $this->member($tenant, RoleName::SalesManager);
        $agent = $this->member($tenant, RoleName::SalesAgent);
        $account = $this->connectWhatsApp($tenant);
        $conversation = $this->conversation($tenant, $account, '919876543210');
        $this->runIn($tenant, fn () => $conversation->update(['link_status' => WhatsAppConversation::AMBIGUOUS]));
        $a = $this->lead($tenant, ['phone_e164' => '+919876543210', 'owner_id' => $agent->id]);
        $this->lead($tenant, ['phone_e164' => '+919876543210']);

        $this->actingInTenant($manager, $tenant)->get("/inbox/{$conversation->public_id}")
            ->assertInertia(fn (Assert $p) => $p->has('conversation.link_candidates', 2)->where('conversation.can.link', true));

        $this->actingInTenant($manager, $tenant)->post("/inbox/{$conversation->public_id}/link", ['lead_id' => $a->public_id])->assertSessionHasNoErrors();
        $fresh = $this->runIn($tenant, fn () => $conversation->fresh());
        $this->assertSame($a->id, $fresh->lead_id);
        $this->assertSame(WhatsAppConversation::LINKED, $fresh->link_status);

        // The agent owns the lead now linked, so sees the chat, but may not re-link it.
        $this->actingInTenant($agent, $tenant)->get("/inbox/{$conversation->public_id}")->assertOk();
        $this->actingInTenant($agent, $tenant)->post("/inbox/{$conversation->public_id}/link", ['lead_id' => $a->public_id])->assertForbidden();
    }

    public function test_linking_to_a_lead_of_another_tenant_is_refused(): void
    {
        [$tenant, $admin] = $this->provision('Acme');
        [$beta] = $this->provision('Beta');
        $foreignLead = $this->lead($beta);
        $conversation = $this->conversation($tenant, $this->connectWhatsApp($tenant));

        $this->actingInTenant($admin, $tenant)->post("/inbox/{$conversation->public_id}/link", ['lead_id' => $foreignLead->public_id])->assertSessionHasErrors('lead_id');
        $this->assertNull($this->runIn($tenant, fn () => $conversation->fresh()->lead_id));
    }

    public function test_lead_page_links_to_its_whatsapp_chat_for_people_who_can_see_it(): void
    {
        [$tenant, $admin] = $this->provision('Acme');
        $agent = $this->member($tenant, RoleName::SalesAgent);
        $lead = $this->lead($tenant, ['owner_id' => $agent->id]);
        $conversation = $this->conversation($tenant, $this->connectWhatsApp($tenant), '919800000001', $lead->id);

        $this->actingInTenant($agent, $tenant)->get("/leads/{$lead->public_id}")
            ->assertInertia(fn (Assert $p) => $p->where('whatsapp_conversation_id', $conversation->public_id));
        $this->actingInTenant($admin, $tenant)->get("/leads/{$this->lead($tenant)->public_id}")
            ->assertInertia(fn (Assert $p) => $p->where('whatsapp_conversation_id', null));
    }
}
