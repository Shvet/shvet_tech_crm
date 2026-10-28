<?php

namespace Tests\Feature\Crm;

use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskTest extends TestCase
{
    private Tenant $t;

    private User $admin;

    private User $manager;

    private User $agent;

    private User $agent2;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->t, $this->admin] = $this->provision('Alpha'); // tenant timezone defaults to Asia/Kolkata
        $this->manager = $this->member($this->t, RoleName::SalesManager);
        $this->agent = $this->member($this->t, RoleName::SalesAgent);
        $this->agent2 = $this->member($this->t, RoleName::SalesAgent);
        $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00:00', 'Asia/Kolkata'));
    }

    private function as(User $u): static
    {
        return $this->actingInTenant($u, $this->t);
    }

    private function task(Lead $lead, string $dueLocal, array $attrs = []): FollowUpTask
    {
        return $this->runIn($this->t, fn () => FollowUpTask::query()->create([
            'lead_id' => $lead->id, 'title' => 'Call back', 'priority' => 'medium',
            'due_at' => CarbonImmutable::parse($dueLocal, 'Asia/Kolkata')->utc(),
            'assigned_to' => $this->agent->id, 'created_by' => $this->manager->id, ...$attrs,
        ]));
    }

    private function payload(array $o = []): array
    {
        return ['title' => 'Send quotation', 'description' => 'Include GST', 'priority' => 'high', 'due_at' => '2026-10-30T14:30', ...$o];
    }

    // ------------------------------------------------------------------ creation & assignment

    public function test_scheduling_a_follow_up_stores_everything_and_converts_the_time_to_utc(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);

        $this->as($this->agent)->post("/leads/{$lead->public_id}/tasks", $this->payload())->assertRedirect()->assertSessionHas('status', 'Follow-up scheduled.');

        $task = $this->runIn($this->t, fn () => FollowUpTask::query()->first());
        $this->assertSame(['Send quotation', 'Include GST', 'high', 'open', $this->agent->id, $this->agent->id], [
            $task->title, $task->description, $task->priority->value, $task->status->value, $task->assigned_to, $task->created_by,
        ]);
        $this->assertSame('2026-10-30 09:00:00', $task->due_at->utc()->format('Y-m-d H:i:s'), '14:30 IST is 09:00 UTC');
        $this->assertSame(26, strlen($task->public_id));
    }

    public static function invalidTasks(): array
    {
        return [
            'no title' => [['title' => ''], 'title'], 'tiny title' => [['title' => 'x'], 'title'], 'long title' => [['title' => str_repeat('t', 201)], 'title'],
            'no due date' => [['due_at' => ''], 'due_at'], 'garbage due date' => [['due_at' => 'next tuesday-ish'], 'due_at'],
            'year 1999' => [['due_at' => '1999-01-01T10:00'], 'due_at'], 'year 2200' => [['due_at' => '2200-01-01T10:00'], 'due_at'],
            'bad priority' => [['priority' => 'critical'], 'priority'], 'long description' => [['description' => str_repeat('d', 5001)], 'description'],
            'assignee text' => [['assigned_to' => 'bob'], 'assigned_to'],
        ];
    }

    #[DataProvider('invalidTasks')]
    public function test_invalid_follow_ups_are_rejected(array $override, string $key): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);

        $this->as($this->agent)->post("/leads/{$lead->public_id}/tasks", $this->payload($override))->assertSessionHasErrors($key);

        $this->assertSame(0, $this->runIn($this->t, fn () => FollowUpTask::query()->count()));
    }

    public function test_assignment_rules(): void
    {
        $own = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        $unassigned = $this->lead($this->t);
        $post = fn (User $as, Lead $lead, ?int $to) => $this->as($as)->post("/leads/{$lead->public_id}/tasks", $this->payload($to ? ['assigned_to' => $to] : []));

        // Agents: only themselves, only on their own leads.
        $post($this->agent, $own, $this->agent2->id)->assertSessionHasErrors('assigned_to');
        $post($this->agent, $own, $this->manager->id)->assertSessionHasErrors('assigned_to');
        $post($this->agent, $own, null)->assertSessionHasNoErrors();
        $post($this->agent, $unassigned, null)->assertNotFound();

        // Managers may assign to whoever can SEE the lead.
        $post($this->manager, $own, $this->agent->id)->assertSessionHasNoErrors();
        $post($this->manager, $own, $this->manager->id)->assertSessionHasNoErrors();
        $post($this->manager, $own, $this->admin->id)->assertSessionHasNoErrors();
        $post($this->manager, $own, $this->agent2->id)->assertSessionHasErrors('assigned_to'); // agent2 cannot open this lead
        $post($this->manager, $unassigned, $this->agent->id)->assertSessionHasErrors('assigned_to'); // nobody-owned: agents cannot see it
        $post($this->manager, $unassigned, $this->admin->id)->assertSessionHasNoErrors();

        // 5 accepted: agent→self, manager→agent, manager→manager, manager→admin, manager→admin (unassigned lead)
        $this->assertSame(5, $this->runIn($this->t, fn () => FollowUpTask::query()->count()));
    }

    public function test_inactive_assignees_are_rejected(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        $this->runIn($this->t, fn () => TenantMembership::query()->where('user_id', $this->agent->id)->update(['status' => MembershipStatus::Suspended->value]));

        $this->as($this->manager)->post("/leads/{$lead->public_id}/tasks", $this->payload(['assigned_to' => $this->agent->id]))->assertSessionHasErrors('assigned_to');
    }

    public function test_the_assignee_list_offered_to_managers_only_contains_people_who_can_see_the_lead(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);

        $ids = collect($this->as($this->manager)->get("/leads/{$lead->public_id}")->viewData('page')['props']['options']['assignees'])->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$this->admin->id, $this->manager->id, $this->agent->id])->sort()->values()->all(), $ids);
        $this->as($this->agent)->get("/leads/{$lead->public_id}")->assertInertia(fn ($p) => $p->has('options.assignees', 1)->where('options.assignees.0.id', $this->agent->id));
    }

    public function test_archived_leads_do_not_accept_follow_ups(): void
    {
        $lead = $this->lead($this->t, ['archived_at' => now()]);

        $this->as($this->manager)->post("/leads/{$lead->public_id}/tasks", $this->payload(['assigned_to' => $this->manager->id]))->assertForbidden();
    }

    // ------------------------------------------------------------------ buckets (overdue / today / upcoming), tenant timezone

    public function test_buckets_use_the_tenants_local_day_not_utc(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        // "now" = 28 Oct 10:00 IST (04:30 UTC)
        $expectations = [
            'overdue' => ['2026-10-28 09:59', '2026-10-27 23:00', '2026-10-01 08:00'],
            'today' => ['2026-10-28 10:00', '2026-10-28 18:00', '2026-10-28 23:59'],
            // 00:30 IST on the 29th is still the 28th in UTC (19:00) — it must count as TOMORROW for this tenant.
            'upcoming' => ['2026-10-29 00:00', '2026-10-29 00:30', '2026-11-15 12:00'],
        ];
        foreach ($expectations as $bucket => $times) {
            foreach ($times as $time) {
                $this->task($lead, $time, ['title' => "$bucket @ $time"]);
            }
        }

        foreach ($expectations as $bucket => $times) {
            $got = collect($this->as($this->agent)->get("/tasks?tab=$bucket")->viewData('page')['props']['tasks']['data'])->pluck('title')->all();
            $this->assertEqualsCanonicalizing(array_map(fn ($tm) => "$bucket @ $tm", $times), $got, "bucket $bucket");
        }
        $this->as($this->agent)->get('/tasks')->assertInertia(fn ($p) => $p->where('counts', ['overdue' => 3, 'today' => 3, 'upcoming' => 3]));
    }

    public function test_buckets_follow_a_different_tenant_timezone(): void
    {
        $this->t->update(['timezone' => 'America/Los_Angeles']);
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        // now = 27 Oct 21:30 in Los Angeles (PDT, UTC-7). "End of today" there = 28 Oct 06:59:59 UTC.
        $this->runIn($this->t, function () use ($lead) {
            foreach ([['today', '2026-10-28 06:00:00'], ['upcoming', '2026-10-28 07:30:00']] as [$title, $utc]) {
                FollowUpTask::query()->create(['lead_id' => $lead->id, 'title' => $title, 'due_at' => $utc, 'assigned_to' => $this->agent->id, 'created_by' => $this->agent->id]);
            }
        });

        $this->as($this->agent)->get('/tasks')->assertInertia(fn ($p) => $p->where('counts', ['overdue' => 0, 'today' => 1, 'upcoming' => 1]));
        $this->as($this->agent)->post("/leads/{$lead->public_id}/tasks", $this->payload(['due_at' => '2026-10-27T23:00']));
        $this->assertSame('2026-10-28 06:00:00', $this->runIn($this->t, fn () => FollowUpTask::query()->latest('id')->first()->due_at->utc()->format('Y-m-d H:i:s')));
    }

    public function test_completed_tasks_leave_every_open_bucket(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        $t = $this->task($lead, '2026-10-20 10:00');

        $this->as($this->agent)->post("/tasks/{$t->public_id}/complete", ['outcome' => 'Spoke to him'])->assertRedirect();

        $this->as($this->agent)->get('/tasks')->assertInertia(fn ($p) => $p->where('counts.overdue', 0));
        $this->as($this->agent)->get('/tasks?tab=completed')->assertInertia(fn ($p) => $p->where('tasks.total', 1)->where('tasks.data.0.outcome', 'Spoke to him'));
    }

    // ------------------------------------------------------------------ complete / reopen / update

    public function test_completing_records_actor_time_outcome_and_a_timeline_entry_once(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        $t = $this->task($lead, '2026-10-28 09:00');

        $this->as($this->agent)->post("/tasks/{$t->public_id}/complete", ['outcome' => 'Quote accepted'])->assertRedirect();
        $this->as($this->agent)->post("/tasks/{$t->public_id}/complete", ['outcome' => 'double click'])->assertRedirect(); // idempotent

        $fresh = $t->fresh();
        $this->assertSame(['completed', 'Quote accepted', $this->agent->id], [$fresh->status->value, $fresh->outcome, $fresh->completed_by]);
        $this->assertTrue($fresh->completed_at->equalTo(now()));
        $notes = $this->runIn($this->t, fn () => LeadActivity::query()->where('lead_id', $lead->id)->get());
        $this->assertCount(1, $notes);
        $this->assertSame('Follow-up completed: Call back', $notes[0]->subject);
    }

    public function test_reopening_restores_an_open_task_and_clears_completion_data(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        $t = $this->task($lead, '2026-10-28 09:00');
        $this->as($this->agent)->post("/tasks/{$t->public_id}/complete", ['outcome' => 'x']);

        $this->as($this->agent)->post("/tasks/{$t->public_id}/reopen")->assertRedirect()->assertSessionHas('status', 'Follow-up reopened.');

        $fresh = $t->fresh();
        $this->assertSame('open', $fresh->status->value);
        $this->assertNull($fresh->completed_at);
        $this->assertNull($fresh->completed_by);
        $this->assertNull($fresh->outcome);
    }

    public function test_updating_a_task_changes_only_allowed_fields_and_not_when_completed(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        $t = $this->task($lead, '2026-10-30 10:00');
        $other = $this->lead($this->t, ['owner_id' => $this->agent->id]);

        $this->as($this->agent)->patch("/tasks/{$t->public_id}", ['title' => 'Renamed', 'due_at' => '2026-11-02T16:00', 'priority' => 'low', 'lead_id' => $other->id, 'status' => 'completed', 'created_by' => $this->admin->id])->assertRedirect();

        $fresh = $t->fresh();
        $this->assertSame(['Renamed', 'low', 'open', $lead->id, $this->manager->id], [$fresh->title, $fresh->priority->value, $fresh->status->value, $fresh->lead_id, $fresh->created_by]);
        $this->assertSame('2026-11-02 10:30:00', $fresh->due_at->utc()->format('Y-m-d H:i:s'));

        $this->as($this->agent)->post("/tasks/{$t->public_id}/complete");
        $this->as($this->agent)->patch("/tasks/{$t->public_id}", ['title' => 'Too late'])->assertSessionHasErrors('task');
    }

    public function test_who_may_change_which_task(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        $mine = $this->task($lead, '2026-10-30 10:00', ['assigned_to' => $this->agent->id, 'created_by' => $this->agent->id]);
        $managers = $this->task($lead, '2026-10-30 11:00', ['assigned_to' => $this->manager->id, 'created_by' => $this->manager->id]);
        $others = $this->lead($this->t, ['owner_id' => $this->agent2->id]);
        $foreign = $this->task($others, '2026-10-30 12:00', ['assigned_to' => $this->agent2->id, 'created_by' => $this->agent2->id]);

        // Agent: own task yes; a manager's task on their own lead is visible but read-only; other agents' tasks do not exist for them.
        $this->as($this->agent)->post("/tasks/{$mine->public_id}/complete")->assertRedirect();
        $this->as($this->agent)->post("/tasks/{$managers->public_id}/complete")->assertForbidden();
        $this->as($this->agent)->patch("/tasks/{$managers->public_id}", ['title' => 'x'])->assertForbidden();
        $this->as($this->agent)->post("/tasks/{$foreign->public_id}/complete")->assertNotFound();
        $this->as($this->agent)->patch("/tasks/{$foreign->public_id}", ['title' => 'x'])->assertNotFound();

        // Manager and admin can change anyone's.
        $this->as($this->manager)->post("/tasks/{$foreign->public_id}/complete")->assertRedirect();
        $this->as($this->admin)->post("/tasks/{$managers->public_id}/complete")->assertRedirect();

        foreach ([$mine, $managers, $foreign] as $task) {
            $this->assertSame('completed', $task->fresh()->status->value);
        }
    }

    public function test_changing_the_assignee_re_checks_assignability(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        $t = $this->task($lead, '2026-10-30 10:00');

        $this->as($this->manager)->patch("/tasks/{$t->public_id}", ['assigned_to' => $this->agent2->id])->assertSessionHasErrors('assigned_to');
        $this->as($this->agent)->patch("/tasks/{$t->public_id}", ['assigned_to' => $this->manager->id])->assertSessionHasErrors('assigned_to'); // agents cannot hand work to others
        $this->as($this->manager)->patch("/tasks/{$t->public_id}", ['assigned_to' => $this->admin->id])->assertSessionHasNoErrors();
        $this->assertSame($this->admin->id, $t->fresh()->assigned_to);
    }

    public function test_tasks_of_archived_leads_are_hidden_and_frozen_until_restored(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        $t = $this->task($lead, '2026-10-20 10:00');
        $this->as($this->manager)->post("/leads/{$lead->public_id}/archive");

        $this->as($this->agent)->get('/tasks')->assertInertia(fn ($p) => $p->where('counts.overdue', 0));
        $this->as($this->manager)->get('/tasks?assignee=all')->assertInertia(fn ($p) => $p->where('counts.overdue', 0));
        $this->as($this->agent)->post("/tasks/{$t->public_id}/complete")->assertNotFound();
        $this->as($this->manager)->post("/tasks/{$t->public_id}/complete")->assertForbidden();

        $this->as($this->manager)->post("/leads/{$lead->public_id}/restore");
        $this->as($this->agent)->get('/tasks')->assertInertia(fn ($p) => $p->where('counts.overdue', 1));
    }

    // ------------------------------------------------------------------ lists & filters

    public function test_task_list_scopes_by_role_and_assignee_filter(): void
    {
        $mineLead = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        $theirLead = $this->lead($this->t, ['owner_id' => $this->agent2->id]);
        $this->task($mineLead, '2026-10-20 10:00', ['assigned_to' => $this->agent->id]);
        $this->task($mineLead, '2026-10-20 11:00', ['assigned_to' => $this->manager->id]);
        $this->task($theirLead, '2026-10-20 12:00', ['assigned_to' => $this->agent2->id]);

        $overdue = fn (User $u, string $q = '') => $this->as($u)->get("/tasks?tab=overdue$q")->viewData('page')['props']['tasks']['total'];

        $this->assertSame(1, $overdue($this->agent), 'default filter = assigned to me');
        $this->assertSame(2, $overdue($this->agent, '&assignee=all'), 'agents see tasks on their own leads, never on others');
        $this->assertSame(1, $overdue($this->agent, '&assignee='.$this->agent2->id), 'a user-id filter is ignored for agents (falls back to me)');
        $this->assertSame(1, $overdue($this->manager), 'the manager has exactly one task assigned to them');
    }

    public function test_managers_can_filter_by_any_member_and_see_everything(): void
    {
        $l1 = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        $l2 = $this->lead($this->t, ['owner_id' => $this->agent2->id]);
        $this->task($l1, '2026-10-20 10:00', ['assigned_to' => $this->agent->id]);
        $this->task($l2, '2026-10-20 11:00', ['assigned_to' => $this->agent2->id]);
        $this->task($l2, '2026-10-20 12:00', ['assigned_to' => $this->manager->id]);

        $overdue = fn (string $q) => $this->as($this->manager)->get("/tasks?tab=overdue$q")->viewData('page')['props']['tasks']['total'];

        $this->assertSame(1, $overdue(''));
        $this->assertSame(3, $overdue('&assignee=all'));
        $this->assertSame(1, $overdue('&assignee='.$this->agent2->id));
        $this->as($this->manager)->get('/tasks')->assertInertia(fn ($p) => $p->has('options.members', 4));
        $this->as($this->agent)->get('/tasks')->assertInertia(fn ($p) => $p->where('options.members', []));
    }

    public function test_tab_ordering_and_pagination(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        foreach (range(1, 25) as $i) {
            $this->task($lead, sprintf('2026-10-%02d 10:00', min($i, 27)), ['title' => sprintf('T%02d', $i)]);
        }

        $page1 = $this->as($this->agent)->get('/tasks?tab=overdue')->viewData('page')['props']['tasks'];
        $page2 = $this->as($this->agent)->get('/tasks?tab=overdue&page=2')->viewData('page')['props']['tasks'];

        $this->assertSame(25, $page1['total']);
        $this->assertCount(20, $page1['data']);
        $this->assertCount(5, $page2['data']);
        $this->assertSame('T01', $page1['data'][0]['title'], 'most overdue first');
        $this->assertSame('T25', $page2['data'][4]['title']);
    }

    public function test_an_invalid_tab_falls_back_safely(): void
    {
        $this->as($this->agent)->get('/tasks?tab=%27%3B--')->assertOk()->assertInertia(fn ($p) => $p->where('tab', 'overdue'));
    }

    // ------------------------------------------------------------------ reassignment side effects

    public function test_reassigning_a_lead_moves_open_tasks_the_old_owner_can_no_longer_see(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        $mine = $this->task($lead, '2026-10-30 10:00', ['assigned_to' => $this->agent->id]);
        $mgrs = $this->task($lead, '2026-10-30 11:00', ['assigned_to' => $this->manager->id]);
        $done = $this->task($lead, '2026-10-20 11:00', ['assigned_to' => $this->agent->id, 'status' => 'completed']);

        $this->as($this->manager)->patch("/leads/{$lead->public_id}/owner", ['owner_id' => $this->agent2->id])->assertRedirect();

        $this->assertSame($this->agent2->id, $mine->fresh()->assigned_to, 'the old owner can no longer open the lead, so the task follows the lead');
        $this->assertSame($this->manager->id, $mgrs->fresh()->assigned_to, 'a manager keeps their own task');
        $this->assertSame($this->agent->id, $done->fresh()->assigned_to, 'history of completed work is untouched');
        $this->as($this->agent)->get('/tasks?assignee=all')->assertInertia(fn ($p) => $p->where('counts.overdue', 0)->where('counts.upcoming', 0));
    }

    public function test_unassigning_a_lead_hands_the_old_owners_tasks_to_the_person_doing_it(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        $t = $this->task($lead, '2026-10-30 10:00', ['assigned_to' => $this->agent->id]);

        $this->as($this->manager)->patch("/leads/{$lead->public_id}/owner", ['owner_id' => null])->assertRedirect();

        $this->assertSame($this->manager->id, $t->fresh()->assigned_to);
    }

    public function test_reassignment_is_audited_and_rejects_inactive_owners(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        $this->runIn($this->t, fn () => TenantMembership::query()->where('user_id', $this->agent2->id)->update(['status' => MembershipStatus::Suspended->value]));

        $this->as($this->manager)->patch("/leads/{$lead->public_id}/owner", ['owner_id' => $this->agent2->id])->assertSessionHasErrors('owner_id');
        $this->as($this->manager)->patch("/leads/{$lead->public_id}/owner", ['owner_id' => $this->admin->id])->assertRedirect();

        $log = $this->runIn($this->t, fn () => AuditLog::query()->where('action', 'lead.assigned')->first());
        $this->assertEquals(['from_user_id' => $this->agent->id, 'to_user_id' => $this->admin->id], $log->metadata);
    }
}
