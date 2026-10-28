<?php

namespace Tests\Feature\Crm;

use App\Actions\Leads\AssignLead;
use App\Actions\Leads\ChangeLeadStage;
use App\Actions\Leads\CreateLead;
use App\Actions\Leads\LogActivity;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\EnsureAssignable;
use App\Enums\ActivityType;
use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The actions enforce their own invariants. Form requests are the first line of defence, but imports and
 * integrations will call actions directly, so each guard is proven here WITHOUT a request in front of it.
 */
class ActionGuardsTest extends TestCase
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

    private function in(\Closure $callback): mixed
    {
        return $this->runIn($this->t, function () use ($callback) {
            $this->manager->forgetTenantCaches();

            return $callback();
        });
    }

    private function suspend(User $user): void
    {
        $this->runIn($this->t, fn () => TenantMembership::query()->where('user_id', $user->id)->update(['status' => MembershipStatus::Suspended->value]));
    }

    private function assertInvalid(string $field, \Closure $callback): void
    {
        try {
            $this->in($callback);
            $this->fail("Expected a validation error on [$field]");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_assign_lead_refuses_inactive_and_foreign_owners_even_without_a_form_request(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        [$b] = $this->provision('Beta');
        $outsider = $this->member($b, RoleName::SalesAgent);
        $this->suspend($this->agent);

        $this->assertInvalid('owner_id', fn () => (new AssignLead)($this->manager, $lead, $this->agent->id));
        $this->assertInvalid('owner_id', fn () => (new AssignLead)($this->manager, $lead, $outsider->id));
        $this->assertSame($this->agent->id, $lead->fresh()->owner_id);
    }

    public function test_create_lead_refuses_an_inactive_owner_chosen_by_an_assigner_but_ignores_an_agents_choice(): void
    {
        $this->suspend($this->agent);
        $data = ['full_name' => 'Direct Call', 'phone_e164' => '+919000000123', 'lead_source_id' => $this->sourceId($this->t), 'owner_id' => $this->agent->id];

        $this->assertInvalid('owner_id', fn () => (new CreateLead)($this->manager, $data));
        $this->assertSame(0, $this->runIn($this->t, fn () => Lead::query()->count()));

        // Without leads.assign the requested owner is ignored: the creator owns the lead.
        $agent2 = $this->member($this->t, RoleName::SalesAgent);
        $created = $this->runIn($this->t, fn () => (new CreateLead)($agent2->fresh(), [...$data, 'owner_id' => $this->manager->id]));
        $this->assertSame($agent2->id, $created['lead']->owner_id);
    }

    public function test_change_stage_refuses_foreign_inactive_and_missing_stages_and_archived_leads(): void
    {
        $lead = $this->lead($this->t, [], 'new');
        [$b] = $this->provision('Beta');
        $inactive = $this->stageId($this->t, 'negotiation');
        $this->runIn($this->t, fn () => PipelineStage::query()->whereKey($inactive)->update(['is_active' => false]));

        foreach ([$this->stageId($b, 'won'), $inactive, 987654] as $stageId) {
            $this->assertInvalid('pipeline_stage_id', fn () => (new ChangeLeadStage)($this->manager, $lead, $stageId));
        }

        $archived = $this->lead($this->t, ['archived_at' => now()]);
        $this->assertInvalid('lead', fn () => (new ChangeLeadStage)($this->manager, $archived, $this->stageId($this->t, 'won')));
        $this->assertSame($this->stageId($this->t, 'new'), $lead->fresh()->pipeline_stage_id);
    }

    public function test_ensure_assignable_rejects_removed_members_and_people_who_cannot_see_the_lead(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);
        $other = $this->member($this->t, RoleName::SalesAgent);
        $gone = $this->member($this->t, RoleName::SalesAgent, MembershipStatus::Removed);

        $this->assertInvalid('assigned_to', fn () => EnsureAssignable::check($this->manager, $lead, $gone->id));
        $this->assertInvalid('assigned_to', fn () => EnsureAssignable::check($this->manager, $lead, $other->id));
        $this->assertInvalid('assigned_to', fn () => EnsureAssignable::check($this->agent->fresh(), $lead, $this->manager->id));
        $this->assertSame($this->agent->id, $this->in(fn () => EnsureAssignable::check($this->manager, $lead, $this->agent->id))->id);
    }

    public function test_tasks_and_activities_refuse_archived_leads_at_the_action_level(): void
    {
        $archived = $this->lead($this->t, ['archived_at' => now(), 'owner_id' => $this->agent->id]);
        $due = now()->addDay();

        $this->assertInvalid('lead', fn () => (new CreateTask)($this->manager, $archived, ['title' => 'x', 'due_at' => $due, 'assigned_to' => $this->manager->id]));
        $this->assertInvalid('lead', fn () => (new LogActivity)($this->manager, $archived, ActivityType::Call, null, null, now()));
    }
}
