<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The only way a user gains access to a tenant. Status must be Active to count. */
#[Fillable(['tenant_id', 'user_id', 'status', 'invited_by', 'joined_at', 'invited_at', 'invitation_expires_at'])]
class TenantMembership extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
            'invited_at' => 'datetime',
            'invitation_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function hasPendingInvitation(): bool
    {
        return $this->status === MembershipStatus::Invited
            && $this->invitation_expires_at !== null
            && $this->invitation_expires_at->isFuture();
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }
}
