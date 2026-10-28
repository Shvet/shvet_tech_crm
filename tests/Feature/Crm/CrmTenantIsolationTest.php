<?php

namespace Tests\Feature\Crm;

use App\Enums\RoleName;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadStageHistory;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Exceptions\TenantContextMissing;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Tenant A's most privileged user attacks tenant B's data through every Phase 2 surface. */
class CrmTenantIsolationTest extends TestCase
{
    private Tenant $a;

    private Tenant $b;

    private User $adminA;

    private User $adminB;

    private User $agentB;

    private Lead $leadA;

    private Lead $leadB;

    private FollowUpTask $taskB;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->adminA] = $this->provision('Alpha');
        [$this->b, $this->adminB] = $this->provision('Beta');
        $this->agentB = $this->member($this->b, RoleName::SalesAgent);

        $this->leadA = $this->lead($this->a, ['full_name' => 'Alpha Lead', 'owner_id' => $this->adminA->id]);
        $this->leadB = $this->lead($this->b, ['full_name' => 'Beta Secret Lead', 'phone_e164' => '+919000011111', 'email' => 'secret@beta.test', 'owner_id' => $this->agentB->id]);

        $this->taskB = $this->runIn($this->b, fn () => FollowUpTask::query()->create([
            'lead_id' => $this->leadB->id, 'title' => 'Beta task', 'due_at' => now()->subDay(),
            'assigned_to' => $this->agentB->id, 'created_by' => $this->adminB->id,
        ]));
    }

    private function asA(): static
    {
        return $this->actingInTenant($this->adminA, $this->a);
    }

    /** @return array<string, array{0: string, 1: string, 2: array}> */
    public static function leadEndpoints(): array
    {
        return [
            'show' => ['get', '', []],
            'edit' => ['get', '/edit', []],
            'update' => ['patch', '', ['full_name' => 'Pwned', 'phone' => '9876543210', 'lead_source_id' => 1]],
            'stage' => ['patch', '/stage', ['pipeline_stage_id' => 1]],
            'owner' => ['patch', '/owner', ['owner_id' => null]],
            'archive' => ['post', '/archive', []],
            'restore' => ['post', '/restore', []],
            'log activity' => ['post', '/activities', ['type' => 'note', 'body' => 'x']],
            'create task' => ['post', '/tasks', ['title' => 'x', 'due_at' => '2030-01-01T10:00']],
        ];
    }

    #[DataProvider('leadEndpoints')]
    public function test_another_tenants_lead_is_a_404_on_every_endpoint(string $method, string $suffix, array $payload): void
    {
        $this->asA()->{$method}("/leads/{$this->leadB->public_id}{$suffix}", $payload)->assertNotFound();

        $this->assertSame('Beta Secret Lead', $this->leadB->fresh()->full_name);
        $this->assertNull($this->leadB->fresh()->archived_at);
    }

    /** @return array<string, array{0: string, 1: string, 2: array}> */
    public static function taskEndpoints(): array
    {
        return [
            'update' => ['patch', '', ['title' => 'Pwned']],
            'complete' => ['post', '/complete', ['outcome' => 'x']],
            'reopen' => ['post', '/reopen', []],
        ];
    }

    #[DataProvider('taskEndpoints')]
    public function test_another_tenants_task_is_a_404_on_every_endpoint(string $method, string $suffix, array $payload): void
    {
        $this->asA()->{$method}("/tasks/{$this->taskB->public_id}{$suffix}", $payload)->assertNotFound();

        $this->assertSame('Beta task', $this->taskB->fresh()->title);
        $this->assertSame('open', $this->taskB->fresh()->status->value);
    }

    public function test_foreign_ids_in_request_bodies_fail_validation(): void
    {
        $foreignSource = $this->sourceId($this->b);
        $foreignStage = $this->stageId($this->b, 'qualified');

        $this->asA()->post('/leads', $this->leadPayload($this->a, ['lead_source_id' => $foreignSource]))->assertSessionHasErrors('lead_source_id');
        $this->asA()->post('/leads', $this->leadPayload($this->a, ['pipeline_stage_id' => $foreignStage]))->assertSessionHasErrors('pipeline_stage_id');
        $this->asA()->post('/leads', $this->leadPayload($this->a, ['owner_id' => $this->agentB->id]))->assertSessionHasErrors('owner_id');
        $this->asA()->patch("/leads/{$this->leadA->public_id}/stage", ['pipeline_stage_id' => $foreignStage])->assertSessionHasErrors('pipeline_stage_id');
        $this->asA()->patch("/leads/{$this->leadA->public_id}/owner", ['owner_id' => $this->agentB->id])->assertSessionHasErrors('owner_id');
        $this->asA()->post("/leads/{$this->leadA->public_id}/tasks", ['title' => 'x', 'due_at' => '2030-01-01T10:00', 'assigned_to' => $this->agentB->id])->assertSessionHasErrors('assigned_to');

        $this->assertSame(1, $this->runIn($this->a, fn () => Lead::query()->count()), 'no lead was created');
        $this->assertSame(0, $this->runIn($this->a, fn () => FollowUpTask::query()->count()));
    }

    public function test_a_forged_tenant_id_in_the_payload_is_ignored(): void
    {
        $this->asA()->post('/leads', $this->leadPayload($this->a, ['tenant_id' => $this->b->id, 'public_id' => 'FORGED']))->assertSessionHasNoErrors();

        $created = $this->runIn($this->a, fn () => Lead::query()->where('full_name', 'Asha Verma')->first());
        $this->assertSame($this->a->id, $created->tenant_id);
        $this->assertNotSame('FORGED', $created->public_id);
        $this->assertSame(0, $this->runIn($this->b, fn () => Lead::query()->where('full_name', 'Asha Verma')->count()));
    }

    public function test_lists_search_pipeline_tasks_and_dashboard_never_include_other_tenants(): void
    {
        $this->asA()->get('/leads')->assertInertia(fn ($p) => $p->where('leads.total', 1)->where('leads.data.0.full_name', 'Alpha Lead'));
        $this->asA()->get('/leads?q=Secret')->assertInertia(fn ($p) => $p->where('leads.total', 0));
        $this->asA()->get('/leads?q=9000011111')->assertInertia(fn ($p) => $p->where('leads.total', 0));
        $this->asA()->get('/leads?q=secret@beta.test')->assertInertia(fn ($p) => $p->where('leads.total', 0));
        $this->asA()->get('/leads?owner='.$this->agentB->id)->assertInertia(fn ($p) => $p->where('leads.total', 0));
        $this->asA()->get('/leads?source='.$this->sourceId($this->b))->assertInertia(fn ($p) => $p->where('leads.total', 0));

        $this->asA()->get('/pipeline')->assertInertia(fn ($p) => $p->where('columns.0.count', 1)
            ->where('columns.0.cards.0.full_name', 'Alpha Lead'));
        $this->asA()->get('/tasks?tab=overdue&assignee=all')->assertInertia(fn ($p) => $p->where('tasks.total', 0));
        $this->asA()->get('/tasks?tab=overdue&assignee='.$this->agentB->id)->assertInertia(fn ($p) => $p->where('tasks.total', 0));
        $this->asA()->get('/dashboard')->assertInertia(fn ($p) => $p->where('metrics.totals.leads', 1)->where('metrics.tasks.overdue', 0)->where('task_lists.overdue', []));
    }

    public function test_duplicate_detection_ignores_other_tenants(): void
    {
        $this->asA()->post('/leads', $this->leadPayload($this->a, ['phone' => '9000011111', 'email' => 'secret@beta.test']))->assertSessionHasNoErrors();

        $this->assertSame(2, $this->runIn($this->a, fn () => Lead::query()->count()));
    }

    public function test_a_person_in_both_tenants_only_sees_the_active_tenants_data(): void
    {
        $multi = $this->member($this->a, RoleName::SalesAgent);
        $this->addMembership($this->b, $multi, RoleName::Admin);
        $this->runIn($this->a, fn () => Lead::query()->whereKey($this->leadA->id)->update(['owner_id' => $multi->id]));

        // Admin of B, but acting in A: B's lead is invisible even though they could see it in B.
        $this->actingInTenant($multi, $this->a)->get("/leads/{$this->leadB->public_id}")->assertNotFound();
        $this->actingInTenant($multi, $this->a)->get('/leads')->assertInertia(fn ($p) => $p->where('leads.total', 1));
        $this->actingInTenant($multi, $this->b)->get("/leads/{$this->leadB->public_id}")->assertOk();
        $this->actingInTenant($multi, $this->b)->get("/leads/{$this->leadA->public_id}")->assertNotFound();
    }

    public function test_the_database_itself_rejects_cross_tenant_references_in_every_new_table(): void
    {
        $cases = [
            'task assignee from another tenant' => fn () => $this->runIn($this->a, fn () => FollowUpTask::query()->create([
                'lead_id' => $this->leadA->id, 'title' => 't', 'due_at' => now(), 'assigned_to' => $this->agentB->id, 'created_by' => $this->adminA->id,
            ])),
            'task on another tenants lead' => fn () => DB::table('follow_up_tasks')->insert([
                'tenant_id' => $this->a->id, 'public_id' => 'X'.str_repeat('0', 25), 'lead_id' => $this->leadB->id, 'title' => 't', 'due_at' => now(),
                'assigned_to' => $this->adminA->id, 'created_by' => $this->adminA->id,
            ]),
            'activity actor from another tenant' => fn () => $this->runIn($this->a, fn () => LeadActivity::query()->create([
                'lead_id' => $this->leadA->id, 'user_id' => $this->agentB->id, 'type' => 'note', 'body' => 'x', 'occurred_at' => now(),
            ])),
            'stage history to another tenants stage' => fn () => $this->runIn($this->a, fn () => LeadStageHistory::query()->create([
                'lead_id' => $this->leadA->id, 'to_stage_id' => $this->stageId($this->b, 'won'), 'changed_by' => $this->adminA->id, 'changed_at' => now(),
            ])),
            'lead created_by from another tenant' => fn () => $this->runIn($this->a, fn () => Lead::factory()->create(['created_by' => $this->agentB->id])),
            'lead archived_by from another tenant' => fn () => $this->runIn($this->a, fn () => Lead::factory()->create(['archived_by' => $this->agentB->id])),
        ];

        foreach ($cases as $label => $attempt) {
            try {
                $attempt();
                $this->fail("Database accepted: $label");
            } catch (QueryException $e) {
                $this->assertStringContainsString('foreign key', strtolower($e->getMessage()), $label);
            }
        }
    }

    public function test_a_lead_deleted_through_the_lead_cascade_cannot_orphan_children(): void
    {
        $this->runIn($this->a, fn () => DB::table('leads')->where('id', $this->leadA->id)->delete());

        $this->assertSame(0, DB::table('lead_stage_history')->where('lead_id', $this->leadA->id)->count());
    }

    public function test_queries_without_a_tenant_context_fail_closed_for_the_new_models(): void
    {
        foreach ([Lead::class, FollowUpTask::class, LeadActivity::class, LeadStageHistory::class] as $model) {
            try {
                $model::query()->count();
                $this->fail("$model must not be queryable without a tenant context");
            } catch (TenantContextMissing) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
