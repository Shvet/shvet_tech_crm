<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

class SeederTest extends TestCase
{
    public function test_demo_seeder_builds_two_isolated_tenants_and_a_multi_workspace_user(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(['acme', 'shvet'], Tenant::query()->orderBy('slug')->pluck('slug')->all());
        $multi = User::query()->where('email', 'multi@demo.test')->firstOrFail();
        $this->assertCount(2, $multi->allMemberships());

        $this->post('/login', ['email' => 'agent@shvet.test', 'password' => DatabaseSeeder::DEMO_PASSWORD])->assertRedirect(route('dashboard'));
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p->where('tenant.name', 'Shvet Technologies')->where('tenant.role', 'Sales Agent'));
    }

    public function test_demo_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(\RuntimeException::class);
        (new DatabaseSeeder)->run();
    }
}
