<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A message template synced from Meta. Only APPROVED templates can be sent.
 *
 * @property int $id
 * @property int $whatsapp_account_id
 * @property string $name
 * @property string $language
 * @property string|null $category
 * @property string $status
 * @property array<int, mixed>|null $components
 * @property int $body_param_count
 * @property Carbon $synced_at
 */
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

    /** @return BelongsTo<WhatsAppAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }
}
