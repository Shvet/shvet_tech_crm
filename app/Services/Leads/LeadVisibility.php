<?php

namespace App\Services\Leads;

use App\Enums\Permission;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * THE rule for which leads a user may see inside their tenant (tenant isolation is a separate,
 * automatic layer below this). Everything else — tasks, activities, pipeline, dashboard, search —
 * derives its visibility from here, so there is exactly one place to change.
 *
 *   Admin          leads.view_all   every lead in the workspace
 *   Sales Manager  leads.view_team  every lead in the workspace (no team model yet: team == workspace)
 *   Sales Agent    leads.view_own   only leads they own (owner_id = themselves); unassigned leads are NOT visible
 *
 * Archived leads are visible only to users holding leads.archive (Admin, Sales Manager).
 */
final class LeadVisibility
{
    public const ALL = 'all';

    public const OWN = 'own';

    public const NONE = 'none';

    public static function level(User $user): string
    {
        if ($user->checkPermissionTo(Permission::LeadsViewAll->value) || $user->checkPermissionTo(Permission::LeadsViewTeam->value)) {
            return self::ALL;
        }

        return $user->checkPermissionTo(Permission::LeadsViewOwn->value) ? self::OWN : self::NONE;
    }

    public static function seesArchived(User $user): bool
    {
        return $user->checkPermissionTo(Permission::LeadsArchive->value);
    }

    /** Restrict a Lead query to what $user may see. */
    public static function scope(Builder $query, User $user, bool $includeArchived = false): Builder
    {
        match (self::level($user)) {
            self::NONE => $query->whereRaw('1 = 0'),
            self::OWN => $query->where('leads.owner_id', $user->getKey()),
            default => null,
        };

        if (! ($includeArchived && self::seesArchived($user))) {
            $query->whereNull('leads.archived_at');
        }

        return $query;
    }

    public static function canView(User $user, Lead $lead): bool
    {
        $level = self::level($user);

        if ($level === self::NONE) {
            return false;
        }
        if ($level === self::OWN && (int) $lead->owner_id !== (int) $user->getKey()) {
            return false;
        }

        return ! $lead->isArchived() || self::seesArchived($user);
    }
}
