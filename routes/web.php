<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
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

        Route::get('/team', [TeamController::class, 'index'])->name('team.index');
        Route::post('/team/members', [TeamController::class, 'store'])->middleware('throttle:invitations')->name('team.store');
        Route::patch('/team/members/{membership}', [TeamController::class, 'update'])->name('team.update');
        Route::delete('/team/members/{membership}', [TeamController::class, 'destroy'])->name('team.destroy');
    });
});
