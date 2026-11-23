<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One thread between a workspace number and one contact. Visibility follows the linked lead (see ConversationVisibility).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $whatsapp_account_id
 * @property int $whatsapp_contact_id
 * @property int|null $lead_id
 * @property string $link_status
 * @property Carbon|null $last_message_at
 * @property Carbon|null $last_inbound_at
 * @property int $unread_count
 * @property-read WhatsAppAccount $account
 * @property-read WhatsAppContact $contact
 * @property-read Lead|null $lead
 */
#[Fillable([
    'whatsapp_account_id', 'whatsapp_contact_id', 'lead_id', 'link_status', 'last_message_at', 'last_inbound_at', 'unread_count',
])]
class WhatsAppConversation extends Model
{
    use BelongsToTenant;

    public const LINKED = 'linked';

    public const UNLINKED = 'unlinked';

    public const AMBIGUOUS = 'ambiguous';

    protected $table = 'whatsapp_conversations';

    protected static function booted(): void
    {
        static::creating(function (WhatsAppConversation $c): void {
            $c->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'unread_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** True while Meta allows free-form (non-template) messages: within N hours of the contact's last message. */
    public function serviceWindowOpen(?Carbon $now = null): bool
    {
        return $this->last_inbound_at !== null
            && $this->last_inbound_at->greaterThan(($now ?? now())->copy()->subHours((int) config('whatsapp.service_window_hours', 24)));
    }

    public function serviceWindowClosesAt(): ?Carbon
    {
        return $this->last_inbound_at?->copy()->addHours((int) config('whatsapp.service_window_hours', 24));
    }

    /** @return BelongsTo<WhatsAppAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    /** @return BelongsTo<WhatsAppContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'whatsapp_contact_id');
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return HasMany<WhatsAppMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'whatsapp_conversation_id');
    }
}
