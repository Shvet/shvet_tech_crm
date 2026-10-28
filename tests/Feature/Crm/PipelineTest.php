<?php

namespace Tests\Feature\Crm;

use App\Enums\RoleName;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PipelineTest extends TestCase
{
    private Tenant $t;

    private User $admin;

    private User $manager;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->t, $this->admin] = $this->provision('Alpha');
        $this->manager = $this->member($this->t, RoleName::SalesManager);
        $this->agent = $this->member($this->t, RoleName::SalesAgent);
    }

    private function as(User $u): static
    {
        return $this->actingInTenant($u, $this->t);
    }

    private function history(Lead $lead): Collection
    {
        return $this->runIn($this->t, fn () => LeadStageHistory::query()->where('lead_id', $lead->id)->orderBy('id')->get());
    }

    public function test_moving_a_lead_records_who_when_and_from_to(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id], 'new');
        $new = $this->stageId($this->t, 'new');
        $qualified = $this->stageId($this->t, 'qualified');
        $this->travelTo(now()->addHour()->startOfSecond());

        $this->as($this->agent)->patch("/leads/{$lead->public_id}/stage", ['pipeline_stage_id' => $qualified])->assertRedirect()->assertSessionHas('status', 'Stage updated.');

        $fresh = $lead->fresh();
        $this->assertSame($qualified, $fresh->pipeline_stage_id);
        $this->assertTrue($fresh->stage_changed_at->equalTo(now()));
        $rows = $this->history($lead);
        $this->assertCount(1, $rows); // factory leads have no creation row
        $this->assertSame([$new, $qualified, $this->agent->id], [$rows[0]->from_stage_id, $rows[0]->to_stage_id, $rows[0]->changed_by]);
        $this->assertTrue($rows[0]->changed_at->equalTo(now()));
    }

    public function test_the_full_journey_is_recorded_in_order_and_shown_on_the_timeline(): void
    {
        $this->as($this->manager)->post('/leads', $this->leadPayload($this->t));
        $lead = $this->runIn($this->t, fn () => Lead::query()->first());

        foreach (['contacted', 'qualified', 'proposal_sent', 'won'] as $key) {
            $this->as($this->manager)->patch("/leads/{$lead->public_id}/stage", ['pipeline_stage_id' => $this->stageId($this->t, $key)])->assertRedirect();
        }

        $this->assertSame(
            [null, 'new', 'contacted', 'qualified', 'proposal_sent'],
            $this->history($lead)->map(fn ($h) => $h->from_stage_id ? $this->runIn($this->t, fn () => $h->fromStage->key) : null)->all(),
        );
        $this->as($this->manager)->get("/leads/{$lead->public_id}")->assertInertia(fn ($p) => $p
            ->has('timeline', 5)
            ->where('timeline.0.kind', 'stage')->where('timeline.0.to', 'Won')->where('timeline.0.from', 'Proposal Sent')->where('timeline.0.user', $this->manager->name));
    }

    public function test_moving_to_the_current_stage_is_a_no_op_with_no_history(): void
    {
        $lead = $this->lead($this->t, [], 'new');

        $this->as($this->manager)->patch("/leads/{$lead->public_id}/stage", ['pipeline_stage_id' => $this->stageId($this->t, 'new')])
            ->assertRedirect()->assertSessionHas('status', 'Lead is already in that stage.');

        $this->assertCount(0, $this->history($lead));
    }

    public function test_stage_changes_are_validated_against_the_tenants_active_stages(): void
    {
        $lead = $this->lead($this->t, [], 'new');
        $inactive = $this->stageId($this->t, 'negotiation');
        $this->runIn($this->t, fn () => PipelineStage::query()->whereKey($inactive)->update(['is_active' => false]));

        foreach (['', 'abc', 999999, $inactive, -1] as $bad) {
            $this->as($this->manager)->patch("/leads/{$lead->public_id}/stage", ['pipeline_stage_id' => $bad])->assertSessionHasErrors('pipeline_stage_id');
        }
        $this->assertSame($this->stageId($this->t, 'new'), $lead->fresh()->pipeline_stage_id);
        $this->assertCount(0, $this->history($lead));
    }

    public function test_a_stale_board_gets_a_conflict_instead_of_silently_overwriting(): void
    {
        $lead = $this->lead($this->t, [], 'new');
        $new = $this->stageId($this->t, 'new');
        $contacted = $this->stageId($this->t, 'contacted');
        $qualified = $this->stageId($this->t, 'qualified');

        // Someone else moves it first.
        $this->as($this->admin)->patch("/leads/{$lead->public_id}/stage", ['pipeline_stage_id' => $contacted, 'expected_stage_id' => $new])->assertRedirect();

        // A stale client still thinks it is in "new".
        $this->as($this->manager)->patch("/leads/{$lead->public_id}/stage", ['pipeline_stage_id' => $qualified, 'expected_stage_id' => $new])
            ->assertSessionHasErrors('stage');

        $this->assertSame($contacted, $lead->fresh()->pipeline_stage_id);
        $this->assertCount(1, $this->history($lead));
    }

    public function test_archived_leads_cannot_be_moved(): void
    {
        $lead = $this->lead($this->t, ['archived_at' => now()], 'new');

        $this->as($this->manager)->patch("/leads/{$lead->public_id}/stage", ['pipeline_stage_id' => $this->stageId($this->t, 'won')])->assertForbidden();
        $this->assertCount(0, $this->history($lead));
    }

    public function test_agents_cannot_move_leads_they_do_not_own_and_nothing_is_recorded(): void
    {
        $other = $this->member($this->t, RoleName::SalesAgent);
        $lead = $this->lead($this->t, ['owner_id' => $other->id], 'new');

        $this->as($this->agent)->patch("/leads/{$lead->public_id}/stage", ['pipeline_stage_id' => $this->stageId($this->t, 'won')])->assertNotFound();

        $this->assertCount(0, $this->history($lead));
    }

    public function test_history_is_append_only_in_the_model_and_in_the_database(): void
    {
        $lead = $this->lead($this->t, [], 'new');
        $this->as($this->manager)->patch("/leads/{$lead->public_id}/stage", ['pipeline_stage_id' => $this->stageId($this->t, 'won')]);
        $row = $this->history($lead)->first();

        $this->runIn($this->t, function () use ($row) {
            try {
                $row->update(['to_stage_id' => 1]);
                $this->fail('model update allowed');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
            try {
                $row->delete();
                $this->fail('model delete allowed');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        });

        foreach (['UPDATE lead_stage_history SET changed_by = NULL', 'DELETE FROM lead_stage_history'] as $sql) {
            try {
                DB::statement($sql);
                $this->fail("database accepted: $sql");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
    }

    public function test_stage_changes_by_two_users_in_a_row_keep_a_consistent_chain(): void
    {
        $lead = $this->lead($this->t, [], 'new');
        $ids = collect(['contacted', 'qualified', 'contacted', 'lost'])->map(fn ($k) => $this->stageId($this->t, $k));

        foreach ($ids as $i => $id) {
            $this->as($i % 2 ? $this->admin : $this->manager)->patch("/leads/{$lead->public_id}/stage", ['pipeline_stage_id' => $id])->assertRedirect();
        }

        $rows = $this->history($lead);
        // Each row's "from" is the previous row's "to": the chain has no gaps even with different actors.
        $this->assertSame($this->stageId($this->t, 'new'), $rows[0]->from_stage_id);
        for ($i = 1; $i < $rows->count(); $i++) {
            $this->assertSame($rows[$i - 1]->to_stage_id, $rows[$i]->from_stage_id);
        }
        $this->assertSame($ids->last(), $lead->fresh()->pipeline_stage_id);
    }

    // ------------------------------------------------------------------ the board itself

    public function test_the_board_lists_active_stages_in_order_with_counts_and_cards(): void
    {
        $this->lead($this->t, ['full_name' => 'First'], 'new');
        $this->lead($this->t, ['full_name' => 'Second'], 'new');
        $this->lead($this->t, ['full_name' => 'Third'], 'won');

        $this->as($this->manager)->get('/pipeline')->assertInertia(fn ($p) => $p
            ->where('columns.0.stage.key', 'new')->where('columns.0.count', 2)->has('columns.0.cards', 2)
            ->where('columns.5.stage.key', 'won')->where('columns.5.count', 1)
            ->where('columns.6.stage.key', 'lost')->where('columns.6.count', 0)
            ->has('columns', 7)
            ->missing('columns.0.cards.0.tenant_id')->missing('columns.0.cards.0.owner_id'));
    }

    public function test_deactivated_stages_stay_visible_only_while_they_still_hold_leads(): void
    {
        $lead = $this->lead($this->t, [], 'negotiation');
        $this->runIn($this->t, fn () => PipelineStage::query()->whereIn('key', ['negotiation', 'contacted'])->update(['is_active' => false]));

        // 'contacted' is empty and inactive -> hidden; 'negotiation' still holds a lead -> shown and flagged.
        $this->as($this->manager)->get('/pipeline')->assertInertia(fn ($p) => $p
            ->has('columns', 6)
            ->where('columns.3.stage.key', 'negotiation')->where('columns.3.stage.inactive', true)->where('columns.3.count', 1)
            ->where('columns.0.stage.inactive', false));
        $this->assertSame('Negotiation', $this->runIn($this->t, fn () => $lead->fresh()->stage->name));

        // A lead can leave the deactivated stage, but nothing can be moved INTO it.
        $this->as($this->manager)->patch("/leads/{$lead->public_id}/stage", ['pipeline_stage_id' => $this->stageId($this->t, 'negotiation')])->assertRedirect();
        $this->as($this->manager)->patch("/leads/{$lead->public_id}/stage", ['pipeline_stage_id' => $this->stageId($this->t, 'qualified')])->assertRedirect();
        $this->as($this->manager)->patch("/leads/{$lead->public_id}/stage", ['pipeline_stage_id' => $this->stageId($this->t, 'negotiation')])->assertSessionHasErrors('pipeline_stage_id');
        $this->as($this->manager)->get('/pipeline')->assertInertia(fn ($p) => $p->has('columns', 5));
    }

    public function test_cards_are_capped_per_column_and_report_has_more(): void
    {
        config(['crm.pipeline_cards_per_stage' => 3]);
        foreach (range(1, 5) as $i) {
            $this->lead($this->t, [], 'new');
        }

        $this->as($this->manager)->get('/pipeline')->assertInertia(fn ($p) => $p
            ->where('columns.0.count', 5)->has('columns.0.cards', 3)->where('columns.0.has_more', true));
    }

    public function test_agents_see_only_their_own_leads_on_the_board_with_matching_counts(): void
    {
        $other = $this->member($this->t, RoleName::SalesAgent);
        $this->lead($this->t, ['owner_id' => $this->agent->id, 'full_name' => 'Mine'], 'new');
        $this->lead($this->t, ['owner_id' => $other->id, 'full_name' => 'Theirs'], 'new');
        $this->lead($this->t, ['owner_id' => null, 'full_name' => 'Nobody'], 'new');

        $this->as($this->agent)->get('/pipeline')->assertInertia(fn ($p) => $p
            ->where('columns.0.count', 1)->has('columns.0.cards', 1)->where('columns.0.cards.0.full_name', 'Mine')
            ->where('options.can_filter_owner', false));
        $this->as($this->agent)->get('/pipeline?owner=unassigned')->assertInertia(fn ($p) => $p->where('columns.0.count', 1)->where('filters.owner', null));
    }

    public function test_card_move_permission_matches_the_policy(): void
    {
        $this->lead($this->t, ['owner_id' => $this->agent->id], 'new');

        $this->as($this->agent)->get('/pipeline')->assertInertia(fn ($p) => $p->where('columns.0.cards.0.can_move', true));
        $this->as($this->manager)->get('/pipeline')->assertInertia(fn ($p) => $p->where('columns.0.cards.0.can_move', true));
    }

    public function test_the_owner_filter_works_for_managers(): void
    {
        $this->lead($this->t, ['owner_id' => $this->manager->id], 'new');
        $this->lead($this->t, ['owner_id' => null], 'new');
        $this->lead($this->t, ['owner_id' => $this->agent->id], 'new');

        $this->as($this->manager)->get('/pipeline?owner=me')->assertInertia(fn ($p) => $p->where('columns.0.count', 1));
        $this->as($this->manager)->get('/pipeline?owner=unassigned')->assertInertia(fn ($p) => $p->where('columns.0.count', 1));
        $this->as($this->manager)->get('/pipeline')->assertInertia(fn ($p) => $p->where('columns.0.count', 3));
    }

    public function test_cards_flag_overdue_follow_ups(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id], 'new');
        $this->runIn($this->t, function () use ($lead) {
            foreach ([now()->subDay(), now()->subHours(3), now()->addDay()] as $due) {
                FollowUpTask::query()->create(['lead_id' => $lead->id, 'title' => 't', 'due_at' => $due, 'assigned_to' => $this->agent->id, 'created_by' => $this->agent->id]);
            }
        });

        $this->as($this->agent)->get('/pipeline')->assertInertia(fn ($p) => $p->where('columns.0.cards.0.overdue_tasks', 2));
    }
}
