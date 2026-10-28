<?php

namespace Tests\Feature\Crm;

use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Facades\Tenancy;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The authoritative role × action matrix for Phase 2. Each row is one request made by one role against
 * one kind of lead; the expected outcome is the documented rule, not whatever the code happens to do.
 *
 *   Admin          sees/does everything in the workspace
 *   Sales Manager  sees every lead; may assign and archive; no user administration
 *   Sales Agent    sees and changes ONLY leads they own; cannot assign, archive or see unassigned/others' leads
 */
class RolePermissionMatrixTest extends TestCase
{
    private Tenant $t;

    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->t, $this->u['admin']] = $this->provision('Alpha');
        $this->u['manager'] = $this->member($this->t, RoleName::SalesManager);
        $this->u['agent'] = $this->member($this->t, RoleName::SalesAgent);
        $this->u['agent2'] = $this->member($this->t, RoleName::SalesAgent);
    }

    /** @return array<string, array{0: string, 1: string, 2: int}> role, lead kind, expected status for GET /leads/{id} */
    public static function viewMatrix(): array
    {
        return [
            'admin views own' => ['admin', 'own', 200], 'admin views other agent\'s' => ['admin', 'other', 200], 'admin views unassigned' => ['admin', 'unassigned', 200],
            'manager views own' => ['manager', 'own', 200], 'manager views other agent\'s' => ['manager', 'other', 200], 'manager views unassigned' => ['manager', 'unassigned', 200],
            'agent views own' => ['agent', 'own', 200], 'agent views other agent\'s' => ['agent', 'other', 404], 'agent views unassigned' => ['agent', 'unassigned', 404],
        ];
    }

    #[DataProvider('viewMatrix')]
    public function test_who_can_open_which_lead(string $role, string $kind, int $expected): void
    {
        $lead = $this->leadOfKind($kind, $role);

        $this->actingInTenant($this->u[$role], $this->t)->get("/leads/{$lead->public_id}")->assertStatus($expected);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: int}> role, kind, method, uri suffix, expected */
    public static function writeMatrix(): array
    {
        $rows = [];
        // [action label, method, suffix, payload-key]
        $actions = [
            'edit page' => ['get', '/edit'],
            'archive' => ['post', '/archive'],
        ];
        foreach ([
            // role => [own, other, unassigned] expected statuses per action
            'admin' => ['edit page' => [200, 200, 200], 'archive' => [302, 302, 302]],
            'manager' => ['edit page' => [200, 200, 200], 'archive' => [302, 302, 302]],
            'agent' => ['edit page' => [200, 404, 404], 'archive' => [403, 404, 404]],
        ] as $role => $byAction) {
            foreach ($byAction as $action => $expected) {
                foreach (['own', 'other', 'unassigned'] as $i => $kind) {
                    $rows["$role $action $kind lead"] = [$role, $kind, $actions[$action][0], $actions[$action][1], $expected[$i]];
                }
            }
        }

        return $rows;
    }

    #[DataProvider('writeMatrix')]
    public function test_who_can_edit_or_archive_which_lead(string $role, string $kind, string $method, string $suffix, int $expected): void
    {
        $lead = $this->leadOfKind($kind, $role);

        $this->actingInTenant($this->u[$role], $this->t)->{$method}("/leads/{$lead->public_id}{$suffix}")->assertStatus($expected);
    }

    public function test_only_assigners_can_change_the_owner(): void
    {
        $lead = $this->leadOfKind('own', 'agent');
        $payload = ['owner_id' => $this->u['agent2']->id];

        $this->actingInTenant($this->u['agent'], $this->t)->patch("/leads/{$lead->public_id}/owner", $payload)->assertForbidden();
        $this->assertSame($this->u['agent']->id, $lead->fresh()->owner_id);

        $this->actingInTenant($this->u['manager'], $this->t)->patch("/leads/{$lead->public_id}/owner", $payload)->assertRedirect();
        $this->assertSame($this->u['agent2']->id, $lead->fresh()->owner_id);

        $this->actingInTenant($this->u['admin'], $this->t)->patch("/leads/{$lead->public_id}/owner", ['owner_id' => null])->assertRedirect();
        $this->assertNull($lead->fresh()->owner_id);
    }

    public function test_agent_can_update_and_move_only_their_own_leads(): void
    {
        $own = $this->leadOfKind('own', 'agent');
        $other = $this->leadOfKind('other', 'agent');
        $qualified = $this->stageId($this->t, 'qualified');

        $this->actingInTenant($this->u['agent'], $this->t)
            ->patch("/leads/{$own->public_id}/stage", ['pipeline_stage_id' => $qualified])->assertRedirect();
        $this->assertSame($qualified, $own->fresh()->pipeline_stage_id);

        $this->actingInTenant($this->u['agent'], $this->t)
            ->patch("/leads/{$other->public_id}/stage", ['pipeline_stage_id' => $qualified])->assertNotFound();
        $this->actingInTenant($this->u['agent'], $this->t)
            ->patch("/leads/{$other->public_id}", $this->leadPayload($this->t, ['full_name' => 'Hijack']))->assertNotFound();
        $this->assertNotSame('Hijack', $other->fresh()->full_name);
    }

    public function test_agent_lists_only_their_own_leads_while_managers_and_admins_list_everything(): void
    {
        $this->lead($this->t, ['owner_id' => $this->u['agent']->id]);
        $this->lead($this->t, ['owner_id' => $this->u['agent']->id]);
        $this->lead($this->t, ['owner_id' => $this->u['agent2']->id]);
        $this->lead($this->t, ['owner_id' => null]);

        foreach (['agent' => 2, 'manager' => 4, 'admin' => 4] as $role => $count) {
            $this->actingInTenant($this->u[$role], $this->t)->get('/leads')
                ->assertInertia(fn ($p) => $p->where('leads.total', $count));
        }
    }

    public function test_agent_cannot_pick_an_owner_when_creating_a_lead_and_always_owns_it(): void
    {
        $this->actingInTenant($this->u['agent'], $this->t)
            ->post('/leads', $this->leadPayload($this->t, ['owner_id' => $this->u['agent2']->id]))->assertSessionHasErrors('owner_id');

        $this->actingInTenant($this->u['agent'], $this->t)->post('/leads', $this->leadPayload($this->t))->assertSessionHasNoErrors();

        $this->assertSame($this->u['agent']->id, $this->runIn($this->t, fn () => Lead::query()->latest('id')->first())->owner_id);
    }

    public function test_managers_may_create_unassigned_leads_or_choose_an_owner(): void
    {
        $this->actingInTenant($this->u['manager'], $this->t)->post('/leads', $this->leadPayload($this->t, ['owner_id' => $this->u['agent']->id, 'email' => 'a@example.test', 'phone' => '']))->assertSessionHasNoErrors();
        $this->actingInTenant($this->u['manager'], $this->t)->post('/leads', $this->leadPayload($this->t, ['email' => 'b@example.test', 'phone' => '']))->assertSessionHasNoErrors();

        $owners = $this->runIn($this->t, fn () => Lead::query()->orderBy('id')->pluck('owner_id')->all());
        $this->assertSame([$this->u['agent']->id, null], $owners);
    }

    public function test_agents_cannot_reach_the_archived_view_or_see_archived_leads(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->u['agent']->id, 'archived_at' => now()]);

        $this->actingInTenant($this->u['agent'], $this->t)->get("/leads/{$lead->public_id}")->assertNotFound();
        $this->actingInTenant($this->u['agent'], $this->t)->get('/leads?archived=1')->assertInertia(fn ($p) => $p->where('leads.total', 0)->where('filters.archived', false));
        $this->actingInTenant($this->u['manager'], $this->t)->get('/leads?archived=1')->assertInertia(fn ($p) => $p->where('leads.total', 1)->where('filters.archived', true));
    }

    public function test_role_permission_sets_for_the_crm_actions(): void
    {
        $expected = [
            'admin' => ['leads.view_all', 'leads.create', 'leads.update', 'leads.assign', 'leads.archive', 'tasks.manage', 'dashboard.view'],
            'manager' => ['leads.view_team', 'leads.create', 'leads.update', 'leads.assign', 'leads.archive', 'tasks.manage', 'dashboard.view'],
            'agent' => ['leads.view_own', 'leads.create', 'leads.update', 'tasks.manage', 'dashboard.view'],
        ];
        $denied = ['agent' => ['leads.assign', 'leads.archive', 'leads.view_all', 'leads.view_team', 'users.manage', 'leads.delete']];

        foreach ($expected as $role => $permissions) {
            Tenancy::runAs($this->t, function () use ($role, $permissions) {
                $user = $this->u[$role]->fresh();
                foreach ($permissions as $p) {
                    $this->assertTrue($user->checkPermissionTo($p), "$role should have $p");
                }
            });
        }
        Tenancy::runAs($this->t, function () use ($denied) {
            $agent = $this->u['agent']->fresh();
            foreach ($denied['agent'] as $p) {
                $this->assertFalse($agent->checkPermissionTo($p), "agent must NOT have $p");
            }
        });
    }

    public function test_crm_sync_roles_adds_new_permissions_to_existing_tenants(): void
    {
        // Simulate a tenant provisioned before leads.archive existed.
        Tenancy::runAs($this->t, function () {
            $manager = Role::query()->where('name', RoleName::SalesManager->value)->first();
            $manager->revokePermissionTo(Permission::LeadsArchive->value);
        });
        $lead = $this->lead($this->t);
        $this->actingInTenant($this->u['manager'], $this->t)->post("/leads/{$lead->public_id}/archive")->assertForbidden();

        $this->artisan('crm:sync-roles')->expectsOutputToContain('Synchronized roles for')->assertSuccessful();

        $this->actingInTenant($this->u['manager'], $this->t)->post("/leads/{$lead->public_id}/archive")->assertRedirect();
    }

    public function test_suspended_members_lose_all_lead_access_immediately(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->u['agent']->id]);
        $this->actingInTenant($this->u['agent'], $this->t)->get("/leads/{$lead->public_id}")->assertOk();

        $this->runIn($this->t, fn () => TenantMembership::query()->where('user_id', $this->u['agent']->id)->update(['status' => MembershipStatus::Suspended->value]));

        $this->get("/leads/{$lead->public_id}")->assertRedirect(route('workspaces.index'));
    }

    private function leadOfKind(string $kind, string $role): Lead
    {
        $owner = match ($kind) {
            'own' => $this->u[$role]->id,
            'other' => $this->u[$role === 'agent' ? 'agent2' : 'agent']->id,
            'unassigned' => null,
        };

        return $this->lead($this->t, ['owner_id' => $owner]);
    }
}
