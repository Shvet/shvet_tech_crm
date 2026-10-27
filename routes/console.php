<?php

use App\Actions\Tenancy\ProvisionTenant;
use App\Support\ProductionSafeguards;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Password;
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
