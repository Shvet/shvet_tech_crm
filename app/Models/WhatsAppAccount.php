<?php

namespace App\Models;

use App\Enums\WhatsAppAccountStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A workspace's connected WhatsApp Business number. The access token is encrypted at rest and never serialized. */
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

    public function templates(): HasMany
    {
        return $this->hasMany(WhatsAppTemplate::class, 'whatsapp_account_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(WhatsAppConversation::class, 'whatsapp_account_id');
    }
}
