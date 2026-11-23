<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A message template synced from Meta. Only APPROVED templates can be sent. */
#[Fillable(['whatsapp_account_id', 'name', 'language', 'category', 'status', 'components', 'body_param_count', 'synced_at'])]
class WhatsAppTemplate extends Model
{
    use BelongsToTenant;

    protected $table = 'whatsapp_templates';

    protected function casts(): array
    {
        return ['components' => 'array', 'body_param_count' => 'integer', 'synced_at' => 'datetime'];
    }

    public function isApproved(): bool
    {
        return strtoupper($this->status) === 'APPROVED';
    }

    /** The template's BODY text (with {{1}} placeholders), for previews. */
    public function bodyText(): ?string
    {
        foreach ((array) $this->components as $component) {
            if (is_array($component) && strtoupper((string) ($component['type'] ?? '')) === 'BODY') {
                return isset($component['text']) ? (string) $component['text'] : null;
            }
        }

        return null;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }
}
