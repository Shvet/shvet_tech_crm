<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Support\Facades\Tenancy;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Global identity. Holds no tenant data: access to a tenant exists only through an
 * active TenantMembership, and roles are tenant-scoped (see config/permission.php).
 */
#[Fillable(['name', 'email', 'password', 'phone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Memberships across ALL tenants. Crosses the tenant boundary by design (login, tenant picker).
     *
     * @return Collection<int, TenantMembership>
     */
    public function allMemberships(?MembershipStatus $status = null): Collection
    {
        return Tenancy::withoutScope(fn () => TenantMembership::query()
            ->where('user_id', $this->getKey())
            ->when($status, fn ($q) => $q->where('status', $status->value))
            ->with('tenant')
            ->get());
    }

    /** Drop role/permission relations cached on this instance when the active tenant changes. */
    public function forgetTenantCaches(): void
    {
        $this->unsetRelation('roles')->unsetRelation('permissions');
    }
}
