<?php

namespace App\Services\Leads;

use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\PipelineStage;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Collection;

/** Dropdown data for forms and filters. All lookups are tenant-scoped by the models' global scope. */
class LeadOptions
{
    /** @return Collection<int, array<string, mixed>> */
    public static function stages(): Collection
    {
        return PipelineStage::query()->where('is_active', true)->orderBy('position')->get()
            ->map(fn (PipelineStage $s) => ['id' => $s->id, 'name' => $s->name, 'key' => $s->key, 'outcome' => $s->outcome, 'color' => $s->color]);
    }

    /** @return Collection<int, array<string, mixed>> */
    public static function sources(): Collection
    {
        return LeadSource::query()->where('is_active', true)->orderBy('id')->get()
            ->map(fn (LeadSource $s) => ['id' => $s->id, 'name' => $s->name, 'key' => $s->key]);
    }

    /** Active members of this workspace. @return Collection<int, array{id: int, name: string}> */
    public static function members(): Collection
    {
        return TenantMembership::query()->where('status', MembershipStatus::Active->value)->with('user:id,name')->get()
            ->map(fn (TenantMembership $m) => ['id' => $m->user_id, 'name' => $m->user->name])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /** People a follow-up on $lead may be assigned to by $actor (always includes themselves). */
    public static function assignees(User $actor, Lead $lead): Collection
    {
        if (! $actor->checkPermissionTo(Permission::LeadsAssign->value)) {
            return collect([['id' => $actor->getKey(), 'name' => $actor->name]]);
        }

        $users = User::query()->whereIn('id', self::members()->pluck('id'))->get();

        return $users->filter(function (User $u) use ($lead) {
            $u->forgetTenantCaches();

            return $u->checkPermissionTo(Permission::TasksManage->value) && LeadVisibility::canView($u, $lead);
        })->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }
}
