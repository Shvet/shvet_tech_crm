<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A call, meeting, note, e-mail or other interaction recorded against a lead. Created via LogActivity. */
#[Fillable(['lead_id', 'user_id', 'type', 'subject', 'body', 'occurred_at'])]
class LeadActivity extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['type' => ActivityType::class, 'occurred_at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
