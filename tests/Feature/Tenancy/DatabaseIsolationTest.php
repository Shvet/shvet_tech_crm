<?php

namespace Tests\Feature\Tenancy;

use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Support\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Defense in depth: even if application code is wrong, the database rejects cross-tenant references. */
class DatabaseIsolationTest extends TestCase
{
    public function test_lead_cannot_reference_another_tenants_source(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');
        $foreignSource = $this->runIn($b, fn () => LeadSource::query()->where('key', 'manual')->first());

        $this->expectException(QueryException::class);
        $this->runIn($a, fn () => Lead::factory()->create(['lead_source_id' => $foreignSource->id]));
    }

    public function test_lead_cannot_reference_another_tenants_stage(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');
        $foreignStage = $this->runIn($b, fn () => PipelineStage::query()->where('key', 'new')->first());

        $this->expectException(QueryException::class);
        $this->runIn($a, fn () => Lead::factory()->create(['pipeline_stage_id' => $foreignStage->id]));
    }

    public function test_lead_owner_must_be_a_member_of_the_same_tenant(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');
        $outsider = $this->member($b, RoleName::SalesAgent);

        $this->expectException(QueryException::class);
        $this->runIn($a, fn () => Lead::factory()->create(['owner_id' => $outsider->id]));
    }

    public function test_lead_owner_who_is_a_member_is_accepted(): void
    {
        [$a] = $this->provision('Alpha');
        $agent = $this->member($a, RoleName::SalesAgent);

        $lead = $this->runIn($a, fn () => Lead::factory()->create(['owner_id' => $agent->id]));

        $this->assertSame($agent->id, $lead->owner_id);
    }

    public function test_role_assignment_must_use_a_role_of_the_same_tenant(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');
        $agentA = $this->member($a, RoleName::SalesAgent);
        $roleB = Tenancy::withoutScope(fn () => Role::query()->where('tenant_id', $b->id)->where('name', RoleName::Admin->value)->first());

        $this->expectException(QueryException::class);
        DB::table('model_has_roles')->insert([
            'role_id' => $roleB->id, 'model_type' => $agentA->getMorphClass(), 'model_id' => $agentA->id, 'tenant_id' => $a->id,
        ]);
    }

    public function test_role_cannot_be_assigned_to_a_non_member(): void
    {
        [$a] = $this->provision('Alpha');
        [, $adminB] = $this->provision('Beta');
        $roleA = Tenancy::withoutScope(fn () => Role::query()->where('tenant_id', $a->id)->where('name', RoleName::Admin->value)->first());

        $this->expectException(QueryException::class);
        DB::table('model_has_roles')->insert([
            'role_id' => $roleA->id, 'model_type' => $adminB->getMorphClass(), 'model_id' => $adminB->id, 'tenant_id' => $a->id,
        ]);
    }

    public function test_business_keys_are_unique_per_tenant_not_globally(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');

        // Both tenants already own a "manual" source and a "new" stage (seeded) - no clash.
        $this->assertSame(1, $this->runIn($a, fn () => LeadSource::query()->where('key', 'manual')->count()));
        $this->assertSame(1, $this->runIn($b, fn () => LeadSource::query()->where('key', 'manual')->count()));

        $this->expectException(QueryException::class);
        $this->runIn($a, fn () => LeadSource::query()->create(['key' => 'manual', 'name' => 'Dup']));
    }

    public function test_tenant_slug_is_globally_unique(): void
    {
        Tenant::query()->create(['name' => 'One', 'slug' => 'same']);

        $this->expectException(QueryException::class);
        Tenant::query()->create(['name' => 'Two', 'slug' => 'same']);
    }
}
