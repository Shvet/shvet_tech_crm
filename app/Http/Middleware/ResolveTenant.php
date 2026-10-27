<?php

namespace App\Http\Middleware;

use App\Enums\MembershipStatus;
use App\Enums\TenantStatus;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Facades\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the active tenant for an authenticated request and re-validates it EVERY time:
 * user active, membership active, tenant active. The tenant id is only ever read from the
 * server-side session, never from request input.
 */
class ResolveTenant
{
    public const SESSION_KEY = 'current_tenant_id';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_if($user === null, 401);

        if (! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        $membership = $this->activeMembership($request, $user);

        if ($membership === null) {
            $request->session()->forget(self::SESSION_KEY);

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                abort(403, 'No active workspace.');
            }

            return redirect()->route('workspaces.index');
        }

        $user->forgetTenantCaches();
        $request->attributes->set('membership', $membership);

        $previous = Tenancy::current();
        Tenancy::set($membership->tenant);

        try {
            return $next($request);
        } finally {
            Tenancy::set($previous);
        }
    }

    private function activeMembership(Request $request, User $user): ?TenantMembership
    {
        $sessionTenantId = $request->session()->get(self::SESSION_KEY);

        $candidates = Tenancy::withoutScope(fn () => TenantMembership::query()
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->whereHas('tenant', fn ($q) => $q->where('status', TenantStatus::Active->value))
            ->with('tenant')
            ->get());

        if ($sessionTenantId !== null) {
            return $candidates->firstWhere('tenant_id', (int) $sessionTenantId);
        }

        // Exactly one workspace: pick it. Several: the user must choose.
        if ($candidates->count() === 1) {
            $request->session()->put(self::SESSION_KEY, $candidates->first()->tenant_id);

            return $candidates->first();
        }

        return null;
    }
}
