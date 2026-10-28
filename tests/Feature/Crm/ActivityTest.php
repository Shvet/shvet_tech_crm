<?php

namespace Tests\Feature\Crm;

use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    private Tenant $t;

    private User $manager;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->t, $admin] = $this->provision('Alpha');
        $this->manager = $this->member($this->t, RoleName::SalesManager);
        $this->agent = $this->member($this->t, RoleName::SalesAgent);
        $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00:00', 'Asia/Kolkata'));
    }

    private function as(User $u): static
    {
        return $this->actingInTenant($u, $this->t);
    }

    private function activities(Lead $lead): Collection
    {
        return $this->runIn($this->t, fn () => LeadActivity::query()->where('lead_id', $lead->id)->orderBy('id')->get());
    }

    public static function types(): array
    {
        return ['call' => ['call'], 'meeting' => ['meeting'], 'note' => ['note'], 'email' => ['email'], 'other' => ['other']];
    }

    #[DataProvider('types')]
    public function test_every_activity_type_can_be_recorded_with_actor_and_time(string $type): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);

        $this->as($this->agent)->post("/leads/{$lead->public_id}/activities", ['type' => $type, 'subject' => 'Subject', 'body' => 'Body text'])
            ->assertRedirect()->assertSessionHas('status', 'Activity recorded.');

        $a = $this->activities($lead)->first();
        $this->assertSame([$type, 'Subject', 'Body text', $this->agent->id], [$a->type->value, $a->subject, $a->body, $a->user_id]);
        $this->assertTrue($a->occurred_at->equalTo(now()));
    }

    public function test_a_note_needs_a_body_but_other_types_do_not(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);

        $this->as($this->agent)->post("/leads/{$lead->public_id}/activities", ['type' => 'note'])->assertSessionHasErrors('body');
        $this->as($this->agent)->post("/leads/{$lead->public_id}/activities", ['type' => 'call'])->assertSessionHasNoErrors();
    }

    public function test_activity_input_is_validated(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        $url = "/leads/{$lead->public_id}/activities";

        $this->as($this->agent)->post($url, ['type' => 'sms'])->assertSessionHasErrors('type');
        $this->as($this->agent)->post($url, [])->assertSessionHasErrors('type');
        $this->as($this->agent)->post($url, ['type' => 'call', 'subject' => str_repeat('s', 201)])->assertSessionHasErrors('subject');
        $this->as($this->agent)->post($url, ['type' => 'note', 'body' => str_repeat('b', 5001)])->assertSessionHasErrors('body');
        $this->as($this->agent)->post($url, ['type' => 'call', 'occurred_at' => 'yesterday-ish'])->assertSessionHasErrors('occurred_at');
        $this->as($this->agent)->post($url, ['type' => 'call', 'occurred_at' => '2026-10-28T16:00'])->assertSessionHasErrors('occurred_at'); // 6 hours ahead
        $this->assertCount(0, $this->activities($lead));
    }

    public function test_occurred_at_is_read_in_the_tenant_timezone_and_stored_in_utc(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);

        $this->as($this->agent)->post("/leads/{$lead->public_id}/activities", ['type' => 'call', 'occurred_at' => '2026-10-28T08:30']);

        $this->assertSame('2026-10-28 03:00:00', $this->activities($lead)->first()->occurred_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_only_real_contact_updates_last_contacted_and_never_moves_backwards(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        $post = fn (array $d) => $this->as($this->agent)->post("/leads/{$lead->public_id}/activities", $d);

        $post(['type' => 'note', 'body' => 'internal thought'])->assertSessionHasNoErrors();
        $this->assertNull($lead->fresh()->last_contacted_at, 'a note is not contact');

        $post(['type' => 'call', 'occurred_at' => '2026-10-27T11:00']);
        $this->assertSame('2026-10-27 05:30:00', $lead->fresh()->last_contacted_at->utc()->format('Y-m-d H:i:s'));

        $post(['type' => 'meeting']); // now
        $this->assertTrue($lead->fresh()->last_contacted_at->equalTo(now()));

        $post(['type' => 'call', 'occurred_at' => '2026-10-20T09:00']); // back-dated entry
        $this->assertTrue($lead->fresh()->last_contacted_at->equalTo(now()), 'a back-dated call must not rewind last_contacted_at');
        $this->assertCount(4, $this->activities($lead));
    }

    public function test_agents_can_log_only_on_their_own_leads_and_managers_on_any(): void
    {
        $theirs = $this->lead($this->t, ['owner_id' => $this->manager->id]);
        $own = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        $unassigned = $this->lead($this->t);

        $this->as($this->agent)->post("/leads/{$theirs->public_id}/activities", ['type' => 'call'])->assertNotFound();
        $this->as($this->agent)->post("/leads/{$unassigned->public_id}/activities", ['type' => 'call'])->assertNotFound();
        $this->as($this->agent)->post("/leads/{$own->public_id}/activities", ['type' => 'call'])->assertRedirect();
        $this->as($this->manager)->post("/leads/{$own->public_id}/activities", ['type' => 'call'])->assertRedirect();
        $this->as($this->manager)->post("/leads/{$unassigned->public_id}/activities", ['type' => 'call'])->assertRedirect();

        $this->assertSame([0, 0, 2, 1], [$this->activities($theirs)->count(), $this->activities($unassigned)->count() - 1, $this->activities($own)->count(), $this->activities($unassigned)->count()]);
    }

    public function test_archived_leads_do_not_accept_activity(): void
    {
        $lead = $this->lead($this->t, ['archived_at' => now()]);

        $this->as($this->manager)->post("/leads/{$lead->public_id}/activities", ['type' => 'call'])->assertForbidden();
        $this->assertCount(0, $this->activities($lead));
    }

    public function test_the_timeline_merges_activities_and_stage_moves_newest_first_with_actors(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id], 'new');
        $this->as($this->agent)->post("/leads/{$lead->public_id}/activities", ['type' => 'call', 'subject' => 'Intro call', 'occurred_at' => '2026-10-28T09:00']);
        $this->travelTo(now()->addHour());
        $this->as($this->agent)->patch("/leads/{$lead->public_id}/stage", ['pipeline_stage_id' => $this->stageId($this->t, 'contacted')]);
        $this->travelTo(now()->addHour());
        $this->as($this->agent)->post("/leads/{$lead->public_id}/activities", ['type' => 'note', 'body' => 'Wants a demo']);

        $this->as($this->agent)->get("/leads/{$lead->public_id}")->assertInertia(fn ($p) => $p
            ->has('timeline', 3)
            ->where('timeline.0.kind', 'activity')->where('timeline.0.body', 'Wants a demo')->where('timeline.0.user', $this->agent->name)
            ->where('timeline.1.kind', 'stage')->where('timeline.1.to', 'Contacted')
            ->where('timeline.2.subject', 'Intro call'));
    }

    public function test_markup_in_notes_is_returned_as_plain_data(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        $payload = '<img src=x onerror=alert(1)>';

        $this->as($this->agent)->post("/leads/{$lead->public_id}/activities", ['type' => 'note', 'body' => $payload]);

        $this->as($this->agent)->get("/leads/{$lead->public_id}")->assertInertia(fn ($p) => $p->where('timeline.0.body', $payload));
    }
}
