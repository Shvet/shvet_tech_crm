<?php

namespace Tests\Feature\Crm;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

class PipelineSettingsTest extends TestCase
{
    private Tenant $t;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->t, $this->admin] = $this->provision('Alpha');
    }

    private function as(?User $user = null): static
    {
        return $this->actingInTenant($user ?? $this->admin, $this->t);
    }

    /** @return list<string> stage keys in order */
    private function order(): array
    {
        return $this->runIn($this->t, fn () => PipelineStage::query()->orderBy('position')->orderBy('id')->pluck('key')->all());
    }

    public function test_admins_see_every_stage_with_lead_counts(): void
    {
        $this->lead($this->t, [], 'qualified');
        $this->lead($this->t, [], 'qualified');

        $this->as()->get('/settings/pipeline')->assertOk()->assertInertia(fn ($p) => $p
            ->component('Settings/Pipeline')
            ->has('stages', 7)
            ->where('stages.2.key', 'qualified')
            ->where('stages.2.leads', 2));
    }

    public function test_adding_renaming_and_changing_a_stage(): void
    {
        $this->as()->post('/settings/pipeline/stages', ['name' => 'Site Visit', 'outcome' => 'open'])->assertSessionHasNoErrors();
        $order = $this->order();
        $this->assertSame('site_visit', end($order));

        $id = $this->runIn($this->t, fn () => PipelineStage::query()->where('key', 'site_visit')->value('id'));
        $this->as()->patch("/settings/pipeline/stages/$id", ['name' => 'Site visit done'])->assertSessionHasNoErrors();
        $this->runIn($this->t, fn () => $this->assertSame('Site visit done', PipelineStage::query()->find($id)->name));

        // Names are unique per workspace; another workspace may reuse them.
        $this->as()->post('/settings/pipeline/stages', ['name' => 'Won', 'outcome' => 'won'])->assertSessionHasErrors('name');
        [$beta, $betaAdmin] = $this->provision('Beta');
        $this->actingInTenant($betaAdmin, $beta)->post('/settings/pipeline/stages', ['name' => 'Site visit done', 'outcome' => 'open'])->assertSessionHasNoErrors();

        $this->as()->post('/settings/pipeline/stages', ['name' => 'X', 'outcome' => 'maybe'])->assertSessionHasErrors(['name', 'outcome']);
        $this->runIn($this->t, fn () => $this->assertSame(2, AuditLog::query()->where('action', 'like', 'pipeline.stage_%')->count()));
    }

    public function test_reordering_saves_positions_and_keeps_an_open_stage_first(): void
    {
        $ids = $this->runIn($this->t, fn () => PipelineStage::query()->orderBy('position')->pluck('id', 'key')->all());

        $new = [$ids['contacted'], $ids['new'], ...array_values(array_diff_key($ids, ['new' => 1, 'contacted' => 1]))];
        $this->as()->put('/settings/pipeline/order', ['ids' => $new])->assertSessionHasNoErrors();
        $this->assertSame(['contacted', 'new'], array_slice($this->order(), 0, 2));

        // Won first is refused (new leads would start as won).
        $bad = [$ids['won'], ...array_values(array_diff($new, [$ids['won']]))];
        $this->as()->put('/settings/pipeline/order', ['ids' => $bad])->assertSessionHasErrors('stage');
        $this->assertSame('contacted', $this->order()[0]);

        // A partial or foreign list is refused.
        $this->as()->put('/settings/pipeline/order', ['ids' => array_slice($new, 0, 3)])->assertSessionHasErrors('ids');
        [$beta] = $this->provision('Beta');
        $foreign = $this->runIn($beta, fn () => PipelineStage::query()->value('id'));
        $this->as()->put('/settings/pipeline/order', ['ids' => [...array_slice($new, 0, 6), $foreign]])->assertSessionHasErrors('ids');
    }

    public function test_deactivating_keeps_leads_and_cannot_leave_a_non_open_stage_first(): void
    {
        $lead = $this->lead($this->t, [], 'new');
        $ids = $this->runIn($this->t, fn () => PipelineStage::query()->pluck('id', 'key')->all());

        $this->as()->patch("/settings/pipeline/stages/{$ids['new']}", ['is_active' => false])->assertSessionHasNoErrors();
        $this->runIn($this->t, fn () => $this->assertSame($ids['new'], $lead->fresh()->pipeline_stage_id), 'leads stay where they are');

        // New leads now start in "contacted".
        $this->as()->post('/leads', $this->leadPayload($this->t))->assertSessionHasNoErrors();
        $this->runIn($this->t, fn () => $this->assertSame($ids['contacted'], Lead::query()->latest('id')->value('pipeline_stage_id')));

        // Turning every remaining open stage into won/lost/inactive is refused at the first violation.
        foreach (['contacted', 'qualified', 'proposal_sent'] as $key) {
            $this->as()->patch("/settings/pipeline/stages/{$ids[$key]}", ['is_active' => false])->assertSessionHasNoErrors();
        }
        $this->as()->patch("/settings/pipeline/stages/{$ids['negotiation']}", ['outcome' => 'won'])->assertSessionHasErrors('stage');
        $this->as()->patch("/settings/pipeline/stages/{$ids['negotiation']}", ['is_active' => false])->assertSessionHasErrors('stage');
        $this->runIn($this->t, fn () => $this->assertTrue(PipelineStage::query()->find($ids['negotiation'])->is_active));

        $this->as()->patch("/settings/pipeline/stages/{$ids['new']}", ['is_active' => true])->assertSessionHasNoErrors();
    }

    public function test_only_admins_manage_stages_and_only_their_own(): void
    {
        $manager = $this->member($this->t, RoleName::SalesManager);
        $this->as($manager)->get('/settings/pipeline')->assertForbidden();
        $this->as($manager)->post('/settings/pipeline/stages', ['name' => 'Nope', 'outcome' => 'open'])->assertForbidden();

        [$beta] = $this->provision('Beta');
        $foreign = $this->runIn($beta, fn () => PipelineStage::query()->value('id'));
        $this->as()->patch("/settings/pipeline/stages/$foreign", ['name' => 'Hijacked'])->assertNotFound();
        $this->runIn($beta, fn () => $this->assertNotSame('Hijacked', PipelineStage::query()->find($foreign)->name));
    }
}
