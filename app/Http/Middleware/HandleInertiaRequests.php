<?php

namespace App\Http\Middleware;

use App\Support\Facades\Tenancy;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // Explicit allow-lists only: never serialize whole models to the browser.
            'auth' => fn () => $request->user() ? [
                'user' => $request->user()->only(['name', 'email']),
            ] : null,
            'tenant' => fn () => ($tenant = Tenancy::current()) ? [
                'id' => $tenant->public_id,
                'name' => $tenant->name,
                'timezone' => $tenant->timezone,
                'currency' => $tenant->currency,
                'role' => $request->user()?->getRoleNames()->first(),
                'permissions' => $request->user()?->getAllPermissions()->pluck('name')->values()->all() ?? [],
            ] : null,
            'flash' => fn () => ['status' => $request->session()->get('status'), 'duplicates' => $request->session()->get('duplicates')],
        ];
    }
}
