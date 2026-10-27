<?php

namespace App\Http\Controllers;

use App\Actions\Tenancy\AcceptInvitation;
use App\Enums\MembershipStatus;
use App\Enums\TenantStatus;
use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Support\AuditLogger;
use App\Support\Facades\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Workspace (tenant) picker. Lives outside the `tenant` middleware: it is how a tenant gets chosen. */
class WorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        $memberships = $request->user()->allMemberships()
            ->filter(fn (TenantMembership $m) => $m->tenant->status === TenantStatus::Active
                && ($m->isActive() || $m->hasPendingInvitation()));

        return Inertia::render('Workspaces/Index', [
            'workspaces' => $memberships->map(fn (TenantMembership $m) => [
                'id' => $m->tenant->public_id,
                'name' => $m->tenant->name,
                'invited' => $m->status === MembershipStatus::Invited,
            ])->values(),
        ]);
    }

    public function select(Request $request, Tenant $tenant): RedirectResponse
    {
        $membership = $this->membershipFor($request, $tenant, MembershipStatus::Active);
        abort_if($membership === null, 403);

        $request->session()->regenerate();
        $request->session()->put(ResolveTenant::SESSION_KEY, $tenant->getKey());

        AuditLogger::record('tenant.switched', $tenant, tenantId: $tenant->getKey());

        return redirect()->route('dashboard');
    }

    public function accept(Request $request, Tenant $tenant): RedirectResponse
    {
        $membership = $this->membershipFor($request, $tenant, MembershipStatus::Invited);
        abort_if($membership === null, 403);

        Tenancy::runAs($tenant, fn () => (new AcceptInvitation)($membership));

        return redirect()->route('workspaces.index')->with('status', 'Invitation accepted.');
    }

    private function membershipFor(Request $request, Tenant $tenant, MembershipStatus $status): ?TenantMembership
    {
        if (! $tenant->isActive()) {
            return null;
        }

        return Tenancy::withoutScope(fn () => TenantMembership::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', $request->user()->getKey())
            ->where('status', $status->value)
            ->first());
    }
}
