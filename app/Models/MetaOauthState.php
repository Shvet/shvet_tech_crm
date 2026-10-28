<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Server-side record behind a signed OAuth `state`; only hashes are stored. */
#[Fillable(['user_id', 'state_hash', 'nonce_hash', 'expires_at', 'used_at'])]
class MetaOauthState extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
