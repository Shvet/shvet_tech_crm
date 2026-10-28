<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LeadActivityController;
use App\Http\Controllers\LeadArchiveController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadOwnerController;
use App\Http\Controllers\LeadStageController;
use App\Http\Controllers\Meta\MetaEventController;
use App\Http\Controllers\Meta\MetaIntegrationController;
use App\Http\Controllers\Meta\MetaMappingController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

// There is deliberately no public registration route: users are created by tenant admins / provisioning.

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:password-reset-request')->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:password-reset')->name('password.store');
});

Route::middleware(['auth', EnsureUserIsActive::class, AuthenticateSession::class, 'throttle:240,1'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('/workspaces/{tenant}/select', [WorkspaceController::class, 'select'])->name('workspaces.select');
    Route::post('/workspaces/{tenant}/accept', [WorkspaceController::class, 'accept'])->name('workspaces.accept');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    Route::redirect('/', '/dashboard');

    // Everything below runs inside a validated tenant context.
    Route::middleware('tenant')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        // CRM workflow. {lead}/{task} are resolved THROUGH the user's visibility (see AppServiceProvider),
        // so an id the user may not see is a 404, exactly like an id that does not exist.
        Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
        Route::get('/leads/create', [LeadController::class, 'create'])->name('leads.create');
        Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');
        Route::get('/leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
        Route::get('/leads/{lead}/edit', [LeadController::class, 'edit'])->name('leads.edit');
        Route::patch('/leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
        Route::patch('/leads/{lead}/stage', [LeadStageController::class, 'update'])->name('leads.stage');
        Route::patch('/leads/{lead}/owner', [LeadOwnerController::class, 'update'])->name('leads.owner');
        Route::post('/leads/{lead}/archive', [LeadArchiveController::class, 'archive'])->name('leads.archive');
        Route::post('/leads/{lead}/restore', [LeadArchiveController::class, 'restore'])->name('leads.restore');
        Route::post('/leads/{lead}/activities', [LeadActivityController::class, 'store'])->name('leads.activities.store');
        Route::post('/leads/{lead}/tasks', [TaskController::class, 'store'])->name('leads.tasks.store');

        Route::get('/pipeline', [PipelineController::class, 'index'])->name('pipeline');

        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
        Route::post('/tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
        Route::post('/tasks/{task}/reopen', [TaskController::class, 'reopen'])->name('tasks.reopen');

        Route::get('/team', [TeamController::class, 'index'])->name('team.index');
        Route::post('/team/members', [TeamController::class, 'store'])->middleware('throttle:invitations')->name('team.store');
        Route::patch('/team/members/{membership}', [TeamController::class, 'update'])->name('team.update');
        Route::delete('/team/members/{membership}', [TeamController::class, 'destroy'])->name('team.destroy');

        // Meta Lead Ads (Admin only: every action authorizes integrations.manage). Route params are tenant-scoped public ids.
        Route::prefix('integrations/meta')->name('integrations.meta.')->group(function () {
            Route::get('/', [MetaIntegrationController::class, 'index'])->name('index');
            Route::post('/connect', [MetaIntegrationController::class, 'connect'])->middleware('throttle:meta-admin')->name('connect');
            Route::get('/callback', [MetaIntegrationController::class, 'callback'])->middleware('throttle:meta-admin')->name('callback');
            Route::post('/connections/{metaConnection}/pages', [MetaIntegrationController::class, 'connectPages'])->name('pages.connect');
            Route::post('/connections/{metaConnection}/validate', [MetaIntegrationController::class, 'validateConnection'])->middleware('throttle:meta-admin')->name('connections.validate');
            Route::delete('/connections/{metaConnection}', [MetaIntegrationController::class, 'disconnect'])->name('connections.disconnect');
            Route::post('/pages/{metaPage}/sync-forms', [MetaIntegrationController::class, 'syncForms'])->middleware('throttle:meta-admin')->name('pages.sync');
            Route::post('/pages/{metaPage}/subscribe', [MetaIntegrationController::class, 'resubscribe'])->middleware('throttle:meta-admin')->name('pages.subscribe');
            Route::delete('/pages/{metaPage}', [MetaIntegrationController::class, 'disconnectPage'])->name('pages.disconnect');
            Route::put('/mappings/{metaMapping}', [MetaMappingController::class, 'update'])->name('mappings.update');
            Route::post('/events/{metaEvent}/retry', [MetaEventController::class, 'retry'])->middleware('throttle:meta-admin')->name('events.retry');
            Route::post('/events/{metaEvent}/create-anyway', [MetaEventController::class, 'createAnyway'])->middleware('throttle:meta-admin')->name('events.create-anyway');
            Route::post('/events/{metaEvent}/dismiss', [MetaEventController::class, 'dismiss'])->name('events.dismiss');
        });
    });
});
