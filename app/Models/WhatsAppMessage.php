<?php

namespace App\Models;

use App\Enums\WhatsAppMessageStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A single WhatsApp message. The body is encrypted at rest (decision D4); it is never logged. */
#[Fillable([
    'whatsapp_conversation_id', 'direction', 'wamid', 'kind', 'body', 'template_name', 'template_language',
    'status', 'error_code', 'error_title', 'sent_by', 'provider_timestamp',
])]
#[Hidden(['body'])]
class WhatsAppMessage extends Model
{
    use BelongsToTenant;

    public const IN = 'in';

    public const OUT = 'out';

    protected $table = 'whatsapp_messages';

    protected static function booted(): void
    {
        static::creating(function (WhatsAppMessage $m): void {
            $m->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'status' => WhatsAppMessageStatus::class,
            'provider_timestamp' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'whatsapp_conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
