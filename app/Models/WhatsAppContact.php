<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person who has messaged (or been messaged by) the workspace on WhatsApp. wa_id is Meta's id for them.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $wa_id
 * @property string|null $phone_e164
 * @property string|null $profile_name
 * @property int|null $lead_id
 * @property Carbon|null $opted_out_at
 */
#[Fillable(['wa_id', 'phone_e164', 'profile_name', 'lead_id', 'opted_out_at'])]
class WhatsAppContact extends Model
{
    use BelongsToTenant;

    protected $table = 'whatsapp_contacts';

    protected function casts(): array
    {
        return ['opted_out_at' => 'datetime'];
    }

    public function isOptedOut(): bool
    {
        return $this->opted_out_at !== null;
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
