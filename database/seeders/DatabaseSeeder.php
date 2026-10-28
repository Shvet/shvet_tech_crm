<?php

namespace Database\Seeders;

use App\Actions\Tenancy\ProvisionTenant;
use App\Actions\Tenancy\SyncTenantRoles;
use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadSource;
use App\Models\LeadStageHistory;
use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Facades\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Development/demo data ONLY. Refuses to run outside local/testing: production tenants are created
 * with `php artisan tenants:provision`. The shared demo password is public knowledge.
 */
class DatabaseSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'Demo-Password-123';

    public function run(): void
    {
        // Allow-list, not "not production": a staging host with a public URL must never get known-password accounts.
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demo seeding only runs in local/testing environments. Use tenants:provision.');
        }

        $provision = new ProvisionTenant;
        $shvet = $provision('Shvet Technologies', 'admin@shvet.test', 'Shvet Admin', 'shvet')['tenant'];
        $acme = $provision('Acme Demo Co', 'admin@acme.test', 'Acme Admin', 'acme')['tenant'];

        User::query()->whereIn('email', ['admin@shvet.test', 'admin@acme.test'])
            ->each(fn (User $u) => $u->forceFill(['password' => Hash::make(self::DEMO_PASSWORD), 'last_login_at' => now()])->save());

        $this->addUser($shvet, 'manager@shvet.test', 'Shvet Manager', RoleName::SalesManager);
        $this->addUser($shvet, 'agent@shvet.test', 'Shvet Agent', RoleName::SalesAgent);
        $this->addUser($acme, 'agent@acme.test', 'Acme Agent', RoleName::SalesAgent);

        // One person who belongs to both tenants with different roles.
        $multi = $this->addUser($shvet, 'multi@demo.test', 'Multi Workspace', RoleName::SalesAgent);
        $this->attach($acme, $multi, RoleName::Admin);

        $this->seedCrmData($shvet, $acme);
    }

    /** Sample leads, activities and follow-ups so every screen has something to show (local/testing only). */
    private function seedCrmData(Tenant $shvet, Tenant $acme): void
    {
        $user = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
        [$manager, $agent, $multi] = [$user('manager@shvet.test'), $user('agent@shvet.test'), $user('multi@demo.test')];

        Tenancy::runAs($shvet, function () use ($manager, $agent, $multi) {
            $stage = fn (string $key) => PipelineStage::query()->where('key', $key)->value('id');
            $source = fn (string $key) => LeadSource::query()->where('key', $key)->value('id');

            $rows = [
                // name, company, phone, owner, stage, source, value, priority
                ['Priya Nair', 'Nair Textiles', '+919811122233', $agent, 'new', 'website', 50000, 'high'],
                ['Ravi Shah', 'Shah & Sons', '+919822233344', $agent, 'contacted', 'facebook_ads', 120000, 'medium'],
                ['Meera Iyer', 'Iyer Logistics', '+919833344455', $agent, 'qualified', 'referral', 300000, 'high'],
                ['Karan Patel', 'Patel Pharma', '+919844455566', $agent, 'won', 'website', 450000, 'medium'],
                ['Sunita Rao', 'Rao Exports', '+919855566677', $multi, 'new', 'instagram_ads', 80000, 'low'],
                ['Imran Khan', 'Khan Traders', '+919866677788', $multi, 'proposal_sent', 'whatsapp', 250000, 'high'],
                ['Deepa Menon', 'Menon Foods', '+919877788899', $manager, 'negotiation', 'referral', 600000, 'high'],
                ['Arjun Desai', 'Desai Motors', '+919888899900', $manager, 'lost', 'other', 90000, 'low'],
                ['Neha Gupta', 'Gupta Fabrics', '+919899900011', null, 'new', 'website', null, 'medium'],
                ['Vikram Joshi', 'Joshi Steel', '+919800011122', null, 'new', 'facebook_ads', 150000, 'medium'],
            ];

            foreach ($rows as [$name, $company, $phone, $owner, $stageKey, $sourceKey, $value, $priority]) {
                $lead = Lead::query()->create([
                    'full_name' => $name, 'company_name' => $company, 'phone_e164' => $phone,
                    'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
                    'lead_source_id' => $source($sourceKey), 'pipeline_stage_id' => $stage($stageKey),
                    'owner_id' => $owner?->id, 'created_by' => $manager->id, 'priority' => $priority,
                    'estimated_value' => $value, 'stage_changed_at' => now()->subDays(rand(0, 5)),
                    'created_at' => now()->subDays(rand(1, 25)),
                ]);
                LeadStageHistory::query()->create(['lead_id' => $lead->id, 'from_stage_id' => null, 'to_stage_id' => $stage('new'), 'changed_by' => $manager->id, 'changed_at' => $lead->created_at]);
                if ($stageKey !== 'new') {
                    LeadStageHistory::query()->create(['lead_id' => $lead->id, 'from_stage_id' => $stage('new'), 'to_stage_id' => $lead->pipeline_stage_id, 'changed_by' => $owner?->id ?? $manager->id, 'changed_at' => $lead->stage_changed_at]);
                }
                if ($owner) {
                    LeadActivity::query()->create(['lead_id' => $lead->id, 'user_id' => $owner->id, 'type' => 'call', 'subject' => 'Intro call', 'body' => 'Discussed requirements and budget.', 'occurred_at' => now()->subDays(rand(0, 6))]);
                    $lead->forceFill(['last_contacted_at' => now()->subDays(rand(0, 6))])->save();
                }
            }

            $task = fn (string $name, string $title, $assignee, string $due, string $priority) => FollowUpTask::query()->create([
                'lead_id' => Lead::query()->where('full_name', $name)->value('id'), 'title' => $title, 'priority' => $priority,
                'due_at' => $due, 'assigned_to' => $assignee->id, 'created_by' => $manager->id,
            ]);
            $task('Priya Nair', 'Send product brochure', $agent, now()->subDays(2), 'high');            // overdue
            $task('Ravi Shah', 'Follow up on pricing question', $agent, now()->addHours(2), 'medium');  // later today (usually)
            $task('Meera Iyer', 'Schedule demo', $agent, now()->addDays(3), 'high');                    // upcoming
            $task('Imran Khan', 'Send revised quotation', $multi, now()->subHours(5), 'high');          // overdue
            $task('Deepa Menon', 'Contract review call', $manager, now()->addDay(), 'high');            // upcoming
        });

        Tenancy::runAs($acme, function () {
            Lead::query()->create([
                'full_name' => 'Acme Only Lead', 'company_name' => 'Acme Customer', 'phone_e164' => '+919700000001', 'email' => 'acme.only@example.test',
                'lead_source_id' => LeadSource::query()->where('key', 'manual')->value('id'), 'pipeline_stage_id' => PipelineStage::query()->where('key', 'new')->value('id'),
                'stage_changed_at' => now(),
            ]);
        });
    }

    private function addUser(Tenant $tenant, string $email, string $name, RoleName $role): User
    {
        $user = User::query()->forceCreate([
            'name' => $name, 'email' => $email, 'password' => Hash::make(self::DEMO_PASSWORD),
            'is_active' => true, 'last_login_at' => now(),
        ]);

        return $this->attach($tenant, $user, $role);
    }

    private function attach(Tenant $tenant, User $user, RoleName $role): User
    {
        Tenancy::runAs($tenant, function () use ($tenant, $user, $role): void {
            TenantMembership::query()->create([
                'tenant_id' => $tenant->id, 'user_id' => $user->id,
                'status' => MembershipStatus::Active, 'joined_at' => now(),
            ]);
            $user->forgetTenantCaches();
            $user->assignRole(SyncTenantRoles::roleFor($role));
        });

        return $user;
    }
}
