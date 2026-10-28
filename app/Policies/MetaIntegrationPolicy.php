<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\MetaConnection;
use App\Models\User;

/** Everything Meta-related is Admin-only (integrations.manage). Authorized via Gate::authorize('manage', MetaConnection::class). */
class MetaIntegrationPolicy
{
    public function manage(User $user): bool
    {
        return $user->checkPermissionTo(Permission::IntegrationsManage->value);
    }
}
