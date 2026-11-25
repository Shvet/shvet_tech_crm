<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A Data Deletion or Deauthorize callback from Meta. Platform-level, deliberately not tenant-scoped. */
#[Fillable(['confirmation_code', 'meta_user_hash', 'kind', 'status', 'connections_affected', 'completed_at'])]
class MetaDataDeletionRequest extends Model
{
    public const KIND_DELETION = 'deletion';

    public const KIND_DEAUTHORIZE = 'deauthorize';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    protected function casts(): array
    {
        return [
            'connections_affected' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /** Hash of the app-scoped Facebook user id, keyed with APP_KEY so it cannot be reversed by guessing ids. */
    public static function hashUserId(string $metaUserId): string
    {
        return hash_hmac('sha256', $metaUserId, (string) config('app.key'));
    }
}
