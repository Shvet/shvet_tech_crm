<?php

namespace App\Http\Controllers;

use App\Actions\Tenancy\AddMember;
use App\Actions\Tenancy\ChangeMemberRole;
use App\Actions\Tenancy\SetMemberStatus;
use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Models\TenantMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Membership management for the CURRENT tenant. Route binding is tenant-scoped, so foreign IDs 404. */
class TeamController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', TenantMembership::class);

        $members = TenantMembership::query()->with(['user.roles'])->orderBy('id')->get()
            ->map(fn (TenantMembership $m) => [
                'id' => $m->id,
                'name' => $m->user->name,
                'email' => $m->user->email,
                'role' => $m->user->roles->first()?->name,
                'status' => $m->status->value,
                'joined_at' => $m->joined_at?->toIso8601String(),
            ]);

        return Inertia::render('Team/Index', ['members' => $members, 'roles' => RoleName::values()]);
    }

    public function store(Request $request, AddMember $addMember): RedirectResponse
    {
        Gate::authorize('create', TenantMembership::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(RoleName::class)],
        ]);

        ['sendSetupLink' => $sendLink] = $addMember($request->user(), $data['name'], $data['email'], RoleName::from($data['role']));

        if ($sendLink) {
            // The new person proves control of the address by setting their own password. The broker also
            // refuses to send more than one link per address per minute (auth.passwords.users.throttle).
            Password::sendResetLink(['email' => strtolower($data['email'])]);
        }

        return back()->with('status', 'Invitation created.');
    }

    public function update(Request $request, TenantMembership $membership, ChangeMemberRole $changeRole, SetMemberStatus $setStatus): RedirectResponse
    {
        Gate::authorize('update', $membership);

        $data = $request->validate([
            'role' => ['nullable', Rule::enum(RoleName::class)],
            'status' => ['nullable', Rule::in([MembershipStatus::Active->value, MembershipStatus::Suspended->value])],
        ]);

        if (! empty($data['role'])) {
            $changeRole($membership, RoleName::from($data['role']));
        }
        if (! empty($data['status'])) {
            $setStatus($membership, MembershipStatus::from($data['status']));
        }

        return back()->with('status', 'Member updated.');
    }

    public function destroy(TenantMembership $membership, SetMemberStatus $setStatus): RedirectResponse
    {
        Gate::authorize('delete', $membership);

        $setStatus($membership, MembershipStatus::Removed);

        return back()->with('status', 'Member removed.');
    }
}
