<?php

namespace Tests\Feature\Crm;

use App\Enums\RoleName;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Every dashboard number is checked against hand-computed expectations, per role and per tenant. */
class DashboardTest extends TestCase
{
    private Tenant $t;

    private User $admin;

    private User $manager;

    private User $agent;

    private User $agent2;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->t, $this->admin] = $this->provision('Alpha');
        $this->manager = $this->member($this->t, RoleName::SalesManager);
        $this->agent = $this->member($this->t, RoleName::SalesAgent);
        $this->agent2 = $this->member($this->t, RoleName::SalesAgent);
        $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00:00', 'Asia/Kolkata'));

        // agent: new×2 (1000+2000), qualified×1 (4000), won×1 (8000)         -> 4 leads
        // agent2: new×1 (500), lost×1 (300)                                   -> 2 leads
        // unassigned: contacted×1 (no value)                                  -> 1 lead
        // archived (agent): new×1 (999999)                                    -> excluded everywhere
        $this->lead($this->t, ['owner_id' => $this->agent->id, 'estimated_value' => 1000, 'created_at' => now()->subDays(2)], 'new');
        $this->lead($this->t, ['owner_id' => $this->agent->id, 'estimated_value' => 2000, 'created_at' => now()->subDays(20)], 'new');
        $this->lead($this->t, ['owner_id' => $this->agent->id, 'estimated_value' => 4000], 'qualified');
        $this->lead($this->t, ['owner_id' => $this->agent->id, 'estimated_value' => 8000], 'won');
        $this->lead($this->t, ['owner_id' => $this->agent2->id, 'estimated_value' => 500], 'new');
        $this->lead($this->t, ['owner_id' => $this->agent2->id, 'estimated_value' => 300], 'lost');
        $this->lead($this->t, ['owner_id' => null, 'estimated_value' => null], 'contacted');
        $this->lead($this->t, ['owner_id' => $this->agent->id, 'estimated_value' => 999999, 'archived_at' => now()], 'new');
    }

    private function dash(User $u): array
    {
        return $this->actingInTenant($u, $this->t)->get('/dashboard')->assertOk()->viewData('page')['props'];
    }

    private function counts(array $metrics): array
    {
        return collect($metrics['stages'])->pluck('count', 'key')->all();
    }

    public function test_admin_and_manager_see_the_whole_workspace_excluding_archived(): void
    {
        foreach ([$this->admin, $this->manager] as $user) {
            $m = $this->dash($user)['metrics'];

            $this->assertSame(7, $m['totals']['leads']);
            $this->assertSame(['new' => 3, 'contacted' => 1, 'qualified' => 1, 'proposal_sent' => 0, 'negotiation' => 0, 'won' => 1, 'lost' => 1], $this->counts($m));
            $this->assertSame([5, 1, 1], [$m['totals']['open'], $m['totals']['won'], $m['totals']['lost']]);
            $this->assertEquals(7500.0, (float) $m['totals']['open_value'], 'open pipeline = 1000+2000+500+4000 (won/lost/archived excluded)');
            $this->assertSame(1, $m['totals']['unassigned']);
        }
    }

    public function test_new_this_week_counts_only_recent_visible_leads(): void
    {
        // Factory sets created_at = now for 5 leads, 2 days ago for one, 20 days ago for one (the archived lead is excluded).
        $this->assertSame(6, $this->dash($this->manager)['metrics']['totals']['new_this_week']);
        $this->assertSame(3, $this->dash($this->agent)['metrics']['totals']['new_this_week']);
    }

    public function test_the_agent_dashboard_is_computed_from_their_own_leads_only(): void
    {
        $m = $this->dash($this->agent)['metrics'];

        $this->assertSame(4, $m['totals']['leads']);
        $this->assertSame(['new' => 2, 'contacted' => 0, 'qualified' => 1, 'proposal_sent' => 0, 'negotiation' => 0, 'won' => 1, 'lost' => 0], $this->counts($m));
        $this->assertSame([3, 1, 0], [$m['totals']['open'], $m['totals']['won'], $m['totals']['lost']]);
        $this->assertEquals(7000.0, (float) $m['totals']['open_value']);
        $this->assertNull($m['totals']['unassigned'], 'agents are not told how many unassigned leads exist');

        $m2 = $this->dash($this->agent2)['metrics'];
        $this->assertSame(2, $m2['totals']['leads']);
        $this->assertSame([1, 0, 1], [$m2['totals']['open'], $m2['totals']['won'], $m2['totals']['lost']]);
    }

    public function test_stage_value_sums_per_stage_are_exact(): void
    {
        $byKey = collect($this->dash($this->manager)['metrics']['stages'])->keyBy('key');

        $this->assertEquals(3500.0, (float) $byKey['new']['value']);
        $this->assertEquals(4000.0, (float) $byKey['qualified']['value']);
        $this->assertEquals(0.0, (float) $byKey['proposal_sent']['value']);
    }

    public function test_deactivated_stages_with_leads_still_count_towards_totals(): void
    {
        $this->runIn($this->t, fn () => PipelineStage::query()->where('key', 'qualified')->update(['is_active' => false]));

        $m = $this->dash($this->manager)['metrics'];

        $this->assertSame(7, $m['totals']['leads']);
        $this->assertTrue(collect($m['stages'])->firstWhere('key', 'qualified')['inactive']);
    }

    public function test_recent_activity_is_newest_first_capped_and_limited_to_visible_leads(): void
    {
        [$mine, $theirs, $archived] = [
            $this->runIn($this->t, fn () => Lead::query()->where('owner_id', $this->agent->id)->whereNull('archived_at')->first()),
            $this->runIn($this->t, fn () => Lead::query()->where('owner_id', $this->agent2->id)->first()),
            $this->runIn($this->t, fn () => Lead::query()->whereNotNull('archived_at')->first()),
        ];
        $this->runIn($this->t, function () use ($mine, $theirs, $archived) {
            foreach (range(1, 10) as $i) {
                LeadActivity::query()->create(['lead_id' => $mine->id, 'user_id' => $this->agent->id, 'type' => 'call', 'subject' => "mine $i", 'occurred_at' => now()->subMinutes(100 - $i)]);
            }
            LeadActivity::query()->create(['lead_id' => $theirs->id, 'user_id' => $this->agent2->id, 'type' => 'note', 'body' => 'x', 'subject' => 'theirs', 'occurred_at' => now()->subMinutes(5)]);
            LeadActivity::query()->create(['lead_id' => $archived->id, 'user_id' => $this->agent->id, 'type' => 'note', 'body' => 'x', 'subject' => 'archived', 'occurred_at' => now()->subMinute()]);
        });

        $agentRecent = collect($this->dash($this->agent)['metrics']['recent_activity']);
        $this->assertCount(8, $agentRecent);
        $this->assertSame('mine 10', $agentRecent[0]['subject']);
        $this->assertSame(['mine 10', 'mine 9', 'mine 8', 'mine 7', 'mine 6', 'mine 5', 'mine 4', 'mine 3'], $agentRecent->pluck('subject')->all());
        $this->assertNotContains('theirs', $agentRecent->pluck('subject')->all());
        $this->assertNotContains('archived', $agentRecent->pluck('subject')->all());

        $managerRecent = collect($this->dash($this->manager)['metrics']['recent_activity'])->pluck('subject');
        $this->assertSame('theirs', $managerRecent[0], 'managers see other agents\' activity');
        $this->assertNotContains('archived', $managerRecent->all());
        $this->assertSame($mine->full_name, $agentRecent[0]['lead']['name']);
        $this->assertSame($this->agent->name, $agentRecent[0]['user']);
    }

    public function test_task_counts_and_lists_by_bucket(): void
    {
        $mine = $this->runIn($this->t, fn () => Lead::query()->where('owner_id', $this->agent->id)->whereNull('archived_at')->first());
        $theirs = $this->runIn($this->t, fn () => Lead::query()->where('owner_id', $this->agent2->id)->first());
        $archived = $this->runIn($this->t, fn () => Lead::query()->whereNotNull('archived_at')->first());
        $mk = fn (Lead $l, string $local, string $title, array $o = []) => $this->runIn($this->t, fn () => FollowUpTask::query()->create([
            'lead_id' => $l->id, 'title' => $title, 'due_at' => CarbonImmutable::parse($local, 'Asia/Kolkata')->utc(),
            'assigned_to' => $this->agent->id, 'created_by' => $this->manager->id, ...$o,
        ]));

        $mk($mine, '2026-10-26 10:00', 'overdue-old');
        $mk($mine, '2026-10-28 09:00', 'overdue-new');
        $mk($mine, '2026-10-28 17:00', 'today-1');
        $mk($mine, '2026-10-29 09:00', 'upcoming-1');
        $mk($mine, '2026-10-20 09:00', 'done', ['status' => 'completed']);
        $mk($theirs, '2026-10-27 09:00', 'other agents overdue', ['assigned_to' => $this->agent2->id]);
        $mk($archived, '2026-10-27 09:00', 'archived lead overdue');
        foreach (range(1, 7) as $i) {
            $mk($mine, '2026-11-0'.$i.' 09:00', "later-$i");
        }

        $agent = $this->dash($this->agent);
        $this->assertSame(['overdue' => 2, 'today' => 1, 'upcoming' => 8], $agent['metrics']['tasks']);
        $this->assertSame(['overdue-old', 'overdue-new'], collect($agent['task_lists']['overdue'])->pluck('title')->all());
        $this->assertSame(['today-1'], collect($agent['task_lists']['today'])->pluck('title')->all());
        $this->assertCount(5, $agent['task_lists']['upcoming'], 'lists are capped at 5');
        $this->assertSame('upcoming-1', $agent['task_lists']['upcoming'][0]['title']);
        $this->assertTrue($agent['task_lists']['overdue'][0]['is_overdue']);
        $this->assertSame($this->agent->name, $agent['task_lists']['overdue'][0]['assignee']['name']);

        $manager = $this->dash($this->manager);
        $this->assertSame(['overdue' => 3, 'today' => 1, 'upcoming' => 8], $manager['metrics']['tasks'], 'manager adds the other agent\'s overdue task, still not the archived lead\'s');
    }

    public function test_another_tenants_data_never_reaches_the_dashboard(): void
    {
        [$b, $adminB] = $this->provision('Beta');
        $leadB = $this->lead($b, ['estimated_value' => 5_000_000, 'owner_id' => $adminB->id], 'new');
        $this->runIn($b, function () use ($leadB, $adminB) {
            FollowUpTask::query()->create(['lead_id' => $leadB->id, 'title' => 'beta overdue', 'due_at' => now()->subDay(), 'assigned_to' => $adminB->id, 'created_by' => $adminB->id]);
            LeadActivity::query()->create(['lead_id' => $leadB->id, 'user_id' => $adminB->id, 'type' => 'call', 'subject' => 'beta call', 'occurred_at' => now()]);
        });

        $props = $this->dash($this->admin);

        $this->assertSame(7, $props['metrics']['totals']['leads']);
        $this->assertEquals(7500.0, (float) $props['metrics']['totals']['open_value']);
        $this->assertSame(0, $props['metrics']['tasks']['overdue']);
        $this->assertStringNotContainsString('beta', json_encode($props));
        $this->assertSame(1, $this->actingInTenant($adminB, $b)->get('/dashboard')->viewData('page')['props']['metrics']['totals']['leads']);
    }

    public function test_an_empty_workspace_renders_zeroes_not_errors(): void
    {
        [$e, $adminE] = $this->provision('Empty Co');

        $m = $this->actingInTenant($adminE, $e)->get('/dashboard')->assertOk()->viewData('page')['props']['metrics'];

        $this->assertSame(0, $m['totals']['leads']);
        $this->assertSame(['overdue' => 0, 'today' => 0, 'upcoming' => 0], $m['tasks']);
        $this->assertSame([], $m['recent_activity']);
        $this->assertCount(7, $m['stages']);
    }

    public function test_dashboard_query_count_does_not_grow_with_data(): void
    {
        $mine = $this->runIn($this->t, fn () => Lead::query()->where('owner_id', $this->agent->id)->first());
        $this->runIn($this->t, function () use ($mine) {
            foreach (range(1, 30) as $i) {
                FollowUpTask::query()->create(['lead_id' => $mine->id, 'title' => "t$i", 'due_at' => now()->subHours($i), 'assigned_to' => $this->agent->id, 'created_by' => $this->agent->id]);
                LeadActivity::query()->create(['lead_id' => $mine->id, 'user_id' => $this->agent->id, 'type' => 'call', 'occurred_at' => now()->subMinutes($i)]);
            }
        });

        DB::enableQueryLog();
        $this->actingInTenant($this->manager, $this->t)->get('/dashboard')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(45, $queries, "dashboard ran $queries queries");
    }
}
