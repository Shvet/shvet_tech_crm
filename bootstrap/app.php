<?php

use App\Http\Middleware\EnforceTrustedHost;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware([])->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(EnforceTrustedHost::class);
        $middleware->web(append: [HandleInertiaRequests::class, SecurityHeaders::class]);
        $middleware->alias(['tenant' => ResolveTenant::class]);

        // Behind Nginx/a load balancer the client IP (used by rate limiting and audit logs) comes from
        // X-Forwarded-For - but only trust it from the proxies listed in TRUSTED_PROXIES (see config/trustedproxy.php;
        // it must not be read from the environment directly here: that returns null once the config is cached). Empty = trust none.

        // Tenant must be resolved after authentication and BEFORE route-model binding,
        // otherwise tenant-scoped bindings would run without a tenant context.
        $middleware->appendToPriorityList(after: AuthenticatesRequests::class, append: ResolveTenant::class);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
