<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\RoleName;
use App\Enums\WhatsAppAccountStatus;
use App\Jobs\WhatsApp\DeliverWhatsAppMessage;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\CloudApiClient;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WhatsAppTestHelpers;
use Tests\TestCase;

class SendTest extends TestCase
{
    use WhatsAppTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureWhatsApp();
    }

    private function lastOutbound($tenant): ?WhatsAppMessage
    {
        return $this->runIn($tenant, fn () => WhatsAppMessage::query()->where('direction', 'out')->latest('id')->first());
    }

    public function test_text_reply_inside_the_window_is_sent_with_the_token_in_the_header_only(): void
    {
        Http::fake(['graph.example.test/*' => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.sent00000001']]])]);
        [$tenant, $admin] = $this->provision('Acme');
        $account = $this->connectWhatsApp($tenant);
        $conversation = $this->conversation($tenant, $account);

        $this->actingInTenant($admin, $tenant)
            ->post("/inbox/{$conversation->public_id}/messages", ['type' => 'text', 'body' => 'Thanks, calling you now'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $message = $this->lastOutbound($tenant);
        $this->assertSame('sent', $message->status->value);
        $this->assertSame('wamid.sent00000001', $message->wamid);
        $this->assertSame($admin->id, (int) $message->sent_by);

        Http::assertSent(function (Request $r) use ($account) {
            return str_starts_with($r->url(), 'https://graph.example.test/v99.0/110000000000001/messages?appsecret_proof=')
                && ! str_contains($r->url(), 'WA-TOKEN')
                && $r->hasHeader('Authorization', 'Bearer '.$account->access_token)
                && $r['to'] === '919876543210' && $r['type'] === 'text' && $r['text']['body'] === 'Thanks, calling you now';
        });
    }

    public function test_free_text_outside_the_24h_window_is_refused_but_a_template_is_allowed(): void
    {
        Http::fake(['graph.example.test/*' => Http::response(['messages' => [['id' => 'wamid.tpl000000001']]])]);
        [$tenant, $admin] = $this->provision('Acme');
        $account = $this->connectWhatsApp($tenant);
        $conversation = $this->conversation($tenant, $account, minutesAgo: 60 * 25);
        $template = $this->template($tenant, $account);

        $this->actingInTenant($admin, $tenant)
            ->post("/inbox/{$conversation->public_id}/messages", ['type' => 'text', 'body' => 'Hello?'])
            ->assertSessionHasErrors('body');
        $this->assertNull($this->lastOutbound($tenant));

        $this->actingInTenant($admin, $tenant)
            ->post("/inbox/{$conversation->public_id}/messages", ['type' => 'template', 'template_id' => $template->id, 'params' => ['Ravi', 'our 2BHK project']])
            ->assertSessionHasNoErrors();

        $message = $this->lastOutbound($tenant);
        $this->assertSame('template', $message->kind);
        $this->assertSame('Hi Ravi, thanks for your interest in our 2BHK project.', $message->body);
        $this->assertSame('sent', $message->status->value);
        Http::assertSent(fn (Request $r) => $r['type'] === 'template' && $r['template']['name'] === 'follow_up'
            && $r['template']['components'][0]['parameters'][1]['text'] === 'our 2BHK project');
    }

    public function test_template_rules_are_enforced(): void
    {
        Queue::fake();
        [$tenant, $admin] = $this->provision('Acme');
        $account = $this->connectWhatsApp($tenant);
        $conversation = $this->conversation($tenant, $account);
        $approved = $this->template($tenant, $account);
        $pending = $this->template($tenant, $account, 'draft_one', 'PENDING');
        [$other] = $this->provision('Beta');
        $foreign = $this->template($other, $this->connectWhatsApp($other, '110000000000009'), 'beta_tpl');

        $send = fn (array $data) => $this->actingInTenant($admin, $tenant)->post("/inbox/{$conversation->public_id}/messages", ['type' => 'template'] + $data);

        $send(['template_id' => $pending->id, 'params' => []])->assertSessionHasErrors('template_id');
        $send(['template_id' => $foreign->id, 'params' => ['a', 'b']])->assertSessionHasErrors('template_id');
        $send(['template_id' => $approved->id, 'params' => ['only one']])->assertSessionHasErrors('params');
        $send(['template_id' => $approved->id, 'params' => ['a', 'b', 'c']])->assertSessionHasErrors('params');

        $this->assertNull($this->lastOutbound($tenant));
        Queue::assertNothingPushed();
    }

    public function test_opted_out_contact_and_inactive_account_block_sending(): void
    {
        Queue::fake();
        [$tenant, $admin] = $this->provision('Acme');
        $account = $this->connectWhatsApp($tenant);
        $conversation = $this->conversation($tenant, $account);

        $this->runIn($tenant, fn () => $conversation->contact->update(['opted_out_at' => now()]));
        $this->actingInTenant($admin, $tenant)->post("/inbox/{$conversation->public_id}/messages", ['type' => 'text', 'body' => 'Hi'])->assertSessionHasErrors('body');

        $this->runIn($tenant, fn () => $conversation->contact->update(['opted_out_at' => null]));
        $this->runIn($tenant, fn () => $account->update(['status' => WhatsAppAccountStatus::ReauthorizationRequired]));
        $this->actingInTenant($admin, $tenant)->post("/inbox/{$conversation->public_id}/messages", ['type' => 'text', 'body' => 'Hi'])->assertSessionHasErrors('body');

        Queue::assertNothingPushed();
    }

    public function test_rules_are_rechecked_when_the_job_runs(): void
    {
        Queue::fake();
        Http::fake();
        [$tenant, $admin] = $this->provision('Acme');
        $account = $this->connectWhatsApp($tenant);
        $conversation = $this->conversation($tenant, $account);

        $this->actingInTenant($admin, $tenant)->post("/inbox/{$conversation->public_id}/messages", ['type' => 'text', 'body' => 'Hi'])->assertSessionHasNoErrors();
        $message = $this->lastOutbound($tenant);
        $this->assertSame('queued', $message->status->value);

        // The contact sends STOP before the worker picks the job up.
        $this->runIn($tenant, fn () => $conversation->contact->update(['opted_out_at' => now()]));
        $this->runIn($tenant, fn () => (new DeliverWhatsAppMessage($message->public_id, [], $tenant->id))->handle(app(CloudApiClient::class)));

        $this->assertSame('failed', $message->fresh()->status->value);
        $this->assertSame('opted_out', $message->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_job_is_a_no_op_once_the_message_left_queued(): void
    {
        Http::fake();
        [$tenant] = $this->provision('Acme');
        $account = $this->connectWhatsApp($tenant);
        $conversation = $this->conversation($tenant, $account);
        $message = $this->runIn($tenant, fn () => WhatsAppMessage::query()->create([
            'whatsapp_conversation_id' => $conversation->id, 'direction' => 'out', 'wamid' => 'wamid.done00000001', 'kind' => 'text', 'body' => 'Hi', 'status' => 'sent',
        ]));

        $this->runIn($tenant, fn () => (new DeliverWhatsAppMessage($message->public_id, [], $tenant->id))->handle(app(CloudApiClient::class)));

        Http::assertNothingSent();
    }

    public function test_meta_rejection_fails_the_message_with_a_readable_reason(): void
    {
        Http::fake(['graph.example.test/*' => Http::response(['error' => ['code' => 131047, 'message' => 'Re-engagement message +919876543210', 'fbtrace_id' => 'Abc']], 400)]);
        [$tenant, $admin] = $this->provision('Acme');
        $account = $this->connectWhatsApp($tenant);
        $conversation = $this->conversation($tenant, $account);

        $this->actingInTenant($admin, $tenant)->post("/inbox/{$conversation->public_id}/messages", ['type' => 'text', 'body' => 'Hi'])->assertSessionHasNoErrors();

        $message = $this->lastOutbound($tenant);
        $this->assertSame('failed', $message->status->value);
        $this->assertSame('meta_invalid_request_131047', $message->error_code);
        $this->assertStringContainsString('24 hours', $message->error_title);
        $this->assertStringNotContainsString('9198765', $message->error_title);
    }

    public function test_auth_error_pauses_the_account(): void
    {
        Http::fake(['graph.example.test/*' => Http::response(['error' => ['code' => 190]], 401)]);
        [$tenant, $admin] = $this->provision('Acme');
        $account = $this->connectWhatsApp($tenant);
        $conversation = $this->conversation($tenant, $account);

        $this->actingInTenant($admin, $tenant)->post("/inbox/{$conversation->public_id}/messages", ['type' => 'text', 'body' => 'Hi']);

        $this->assertSame('failed', $this->lastOutbound($tenant)->status->value);
        $this->assertSame(WhatsAppAccountStatus::ReauthorizationRequired, $this->runIn($tenant, fn () => $account->fresh()->status));
    }

    public function test_rate_limit_is_released_for_retry_not_failed(): void
    {
        Http::fake(['graph.example.test/*' => Http::response(['error' => ['code' => 130429]], 400)]);
        [$tenant] = $this->provision('Acme');
        $account = $this->connectWhatsApp($tenant);
        $conversation = $this->conversation($tenant, $account);
        $message = $this->runIn($tenant, fn () => WhatsAppMessage::query()->create([
            'whatsapp_conversation_id' => $conversation->id, 'direction' => 'out', 'kind' => 'text', 'body' => 'Hi', 'status' => 'queued',
        ]));

        $job = new DeliverWhatsAppMessage($message->public_id, [], $tenant->id);
        $fakeJob = new FakeJob;
        $job->setJob($fakeJob);
        $this->runIn($tenant, fn () => $job->handle(app(CloudApiClient::class)));

        $this->assertTrue($fakeJob->isReleased());
        $this->assertSame('queued', $message->fresh()->status->value);
    }

    public function test_job_payload_is_encrypted_on_the_queue(): void
    {
        $this->assertInstanceOf(ShouldBeEncrypted::class, new DeliverWhatsAppMessage('x', ['Ravi'], 1));
    }

    public function test_agents_reply_only_on_their_own_leads_conversations(): void
    {
        Queue::fake();
        [$tenant] = $this->provision('Acme');
        $agent = $this->member($tenant, RoleName::SalesAgent);
        $other = $this->member($tenant, RoleName::SalesAgent);
        $account = $this->connectWhatsApp($tenant);
        $mine = $this->conversation($tenant, $account, '919800000001', $this->lead($tenant, ['owner_id' => $agent->id])->id);
        $theirs = $this->conversation($tenant, $account, '919800000002', $this->lead($tenant, ['owner_id' => $other->id])->id);
        $unlinked = $this->conversation($tenant, $account, '919800000003');

        $this->actingInTenant($agent, $tenant)->post("/inbox/{$mine->public_id}/messages", ['type' => 'text', 'body' => 'Hi'])->assertSessionHasNoErrors();
        $this->actingInTenant($agent, $tenant)->post("/inbox/{$theirs->public_id}/messages", ['type' => 'text', 'body' => 'Hi'])->assertNotFound();
        $this->actingInTenant($agent, $tenant)->post("/inbox/{$unlinked->public_id}/messages", ['type' => 'text', 'body' => 'Hi'])->assertNotFound();
        Queue::assertPushed(DeliverWhatsAppMessage::class, 1);
    }
}
