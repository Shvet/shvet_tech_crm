<?php

use App\Actions\Tenancy\ProvisionTenant;
use App\Actions\Tenancy\SyncTenantRoles;
use App\Models\Tenant;
use App\Support\ProductionSafeguards;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;

Artisan::command('crm:preflight', function () {
    $critical = ProductionSafeguards::critical();
    $warnings = ProductionSafeguards::warnings();

    foreach ($critical as $problem) {
        $this->error("CRITICAL: {$problem}");
    }
    foreach ($warnings as $warning) {
        $this->warn("WARNING:  {$warning}");
    }
    if ($critical === [] && $warnings === []) {
        $this->info('Preflight passed.');
    }

    return $critical === [] ? self::SUCCESS : self::FAILURE;
})->purpose('Check production security configuration (run against the production environment before go-live)');

Artisan::command('crm:sync-roles', function () {
    // Idempotent: aligns every tenant's Admin / Sales Manager / Sales Agent permissions with the code (run after deploys
    // that add permissions; new tenants get them at provisioning).
    $count = 0;
    foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
        (new SyncTenantRoles)($tenant);
        $count++;
    }
    $this->info("Synchronized roles for {$count} tenant(s).");
})->purpose('Re-sync role permissions for all tenants');

// Platform-level operation: not available to any tenant role. Run by an operator on the server.
Artisan::command('tenants:provision {name : Company / workspace name} {admin_email : First Admin e-mail} {--admin-name= : First Admin display name} {--slug= : URL-safe identifier}', function () {
    try {
        $result = (new ProvisionTenant)(
            $this->argument('name'),
            $this->argument('admin_email'),
            $this->option('admin-name') ?: 'Administrator',
            $this->option('slug'),
        );
    } catch (ValidationException $e) {
        foreach ($e->errors() as $messages) {
            $this->error(implode(' ', $messages));
        }

        return self::FAILURE;
    }

    $this->info("Tenant [{$result['tenant']->slug}] provisioned (id {$result['tenant']->public_id}).");

    if ($result['adminIsNew']) {
        Password::sendResetLink(['email' => $result['admin']->email]);
        $this->line('A password-setup link was sent to the new Admin.');
    } else {
        $this->line('Existing user attached as Admin of the new tenant.');
    }

    return self::SUCCESS;
})->purpose('Provision a new tenant with its first Admin');

// Meta Lead Ads housekeeping. Needs `* * * * * php artisan schedule:run` (see README). All are idempotent.
Schedule::command('meta:recover-events')->everyMinute()->withoutOverlapping(10)->onOneServer();
Schedule::command('meta:validate-connections')->dailyAt('03:17')->withoutOverlapping(60)->onOneServer();
Schedule::command('meta:prune')->dailyAt('03:47')->withoutOverlapping(60)->onOneServer();
