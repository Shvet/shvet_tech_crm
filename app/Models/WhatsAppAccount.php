<?php

namespace App\Models;

use App\Enums\WhatsAppAccountStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A workspace's connected WhatsApp Business number. The access token is encrypted at rest and never serialized.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property string $waba_id
 * @property string $phone_number_id
 * @property string|null $display_phone_number
 * @property string|null $verified_name
 * @property string|null $access_token
 * @property WhatsAppAccountStatus $status
 * @property bool $auto_create_leads
 * @property int|null $default_owner_id
 * @property string $subscription_status
 * @property Carbon|null $last_validated_at
 * @property Carbon|null $templates_synced_at
 * @property Carbon|null $last_webhook_at
 * @property string|null $last_error_code
 * @property string|null $last_error_summary
 */
#[Fillable([
    'waba_id', 'phone_number_id', 'display_phone_number', 'verified_name', 'access_token', 'status',
    'auto_create_leads', 'default_owner_id', 'subscription_status', 'last_validated_at', 'templates_synced_at',
    'last_webhook_at', 'last_error_code', 'last_error_summary', 'created_by',
])]
#[Hidden(['access_token'])]
class WhatsAppAccount extends Model
{
    use BelongsToTenant;

    protected $table = 'whatsapp_accounts';

    protected static function booted(): void
    {
        static::creating(function (WhatsAppAccount $a): void {
            $a->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'status' => WhatsAppAccountStatus::class,
            'auto_create_leads' => 'boolean',
            'last_validated_at' => 'datetime',
            'templates_synced_at' => 'datetime',
            'last_webhook_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function isActive(): bool
    {
        return $this->status === WhatsAppAccountStatus::Active && filled($this->access_token);
    }

    /** @return HasMany<WhatsAppTemplate, $this> */
    public function templates(): HasMany
    {
        return $this->hasMany(WhatsAppTemplate::class, 'whatsapp_account_id');
    }

    /** @return HasMany<WhatsAppConversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(WhatsAppConversation::class, 'whatsapp_account_id');
    }
}
