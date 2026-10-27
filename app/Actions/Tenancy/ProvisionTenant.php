<?php

namespace App\Actions\Tenancy;

use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Enums\TenantStatus;
use App\Models\LeadSource;
use App\Models\PipelineStage;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a tenant with its standard roles, lead sources, pipeline and first Admin — atomically.
 * The caller (artisan command / future platform console) is responsible for sending the
 * password-setup link when the admin account is new.
 */
class ProvisionTenant
{
    /** @return array{tenant: Tenant, admin: User, adminIsNew: bool} */
    public function __invoke(string $name, string $adminEmail, string $adminName, ?string $slug = null): array
    {
        $slug = Str::slug($slug ?: $name);

        if ($slug === '' || strlen($slug) > 63) {
            throw ValidationException::withMessages(['slug' => 'Invalid tenant slug.']);
        }

        $adminEmail = Str::lower(trim($adminEmail));
        if (! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['admin_email' => 'Invalid admin e-mail address.']);
        }

        return DB::transaction(function () use ($name, $slug, $adminEmail, $adminName) {
            if (Tenant::query()->where('slug', $slug)->exists()) {
                throw ValidationException::withMessages(['slug' => 'That tenant slug is already taken.']);
            }

            $tenant = Tenant::query()->create(['name' => $name, 'slug' => $slug, 'status' => TenantStatus::Active]);

            (new SyncTenantRoles)($tenant);

            $admin = User::query()->where('email', $adminEmail)->first();
            $adminIsNew = $admin === null;

            // New accounts get an unusable random password; the owner sets theirs via a reset link.
            $admin ??= User::query()->create([
                'name' => $adminName,
                'email' => $adminEmail,
                'password' => Hash::make(Str::random(64)),
            ]);

            Tenancy::runAs($tenant, function () use ($tenant, $admin): void {
                TenantMembership::query()->create([
                    'tenant_id' => $tenant->getKey(),
                    'user_id' => $admin->getKey(),
                    'status' => MembershipStatus::Active,
                    'joined_at' => now(),
                ]);

                $admin->forgetTenantCaches();
                $admin->assignRole(SyncTenantRoles::roleFor(RoleName::Admin));

                $this->seedDefaults($tenant);

                AuditLogger::record('tenant.provisioned', $tenant, ['slug' => $tenant->slug], $tenant->getKey());
            });

            return ['tenant' => $tenant, 'admin' => $admin, 'adminIsNew' => $adminIsNew];
        });
    }

    private function seedDefaults(Tenant $tenant): void
    {
        $sources = [
            'facebook_ads' => 'Facebook Ads', 'instagram_ads' => 'Instagram Ads', 'whatsapp' => 'WhatsApp',
            'website' => 'Website', 'manual' => 'Manual entry', 'referral' => 'Referral', 'other' => 'Other',
        ];
        foreach ($sources as $key => $label) {
            LeadSource::query()->create(['key' => $key, 'name' => $label, 'is_system' => true, 'is_active' => true]);
        }

        $stages = [
            ['new', 'New', 'open'], ['contacted', 'Contacted', 'open'], ['qualified', 'Qualified', 'open'],
            ['proposal_sent', 'Proposal Sent', 'open'], ['negotiation', 'Negotiation', 'open'],
            ['won', 'Won', 'won'], ['lost', 'Lost', 'lost'],
        ];
        foreach ($stages as $i => [$key, $label, $outcome]) {
            PipelineStage::query()->create(['key' => $key, 'name' => $label, 'position' => $i + 1, 'outcome' => $outcome]);
        }

        Setting::query()->create(['key' => 'agents_can_claim_unassigned', 'value' => false]);
    }
}
