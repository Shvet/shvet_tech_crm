<?php

namespace App\Services\Meta;

use App\Exceptions\MetaConnectionException;
use App\Models\MetaOauthState;
use App\Models\User;
use App\Support\Facades\Tenancy;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * Signed, short-lived, single-use OAuth `state`.
 *
 * state = <random token>.<HMAC(token | tenant | user)>. Server side we keep only sha256(token) plus sha256 of a
 * per-flow nonce that also lives in the initiating browser session, so the callback must (a) carry a valid
 * signature, (b) match an unused, unexpired row of the CURRENT tenant and the CURRENT user, and (c) come from
 * the same browser session. The tenant is never read from the request.
 */
class OAuthStateService
{
    private const SESSION_KEY = 'meta_oauth_nonce';

    public function issue(User $user, Session $session): string
    {
        $tenantId = Tenancy::idOrFail();
        $token = Str::random(48);
        $nonce = Str::random(32);

        MetaOauthState::query()->create([
            'user_id' => $user->getKey(),
            'state_hash' => hash('sha256', $token),
            'nonce_hash' => hash('sha256', $nonce),
            'expires_at' => now()->addMinutes((int) config('meta.oauth_state_ttl_minutes', 10)),
        ]);

        $session->put(self::SESSION_KEY, $nonce);

        return $token.'.'.$this->sign($token, $tenantId, (int) $user->getKey());
    }

    /** Validates and burns the state. Any failure throws the same generic exception (no oracle). */
    public function consume(string $state, User $user, Session $session): MetaOauthState
    {
        $fail = fn () => new MetaConnectionException('The Meta authorization request is invalid or has expired. Please start again.');

        $tenantId = Tenancy::idOrFail();
        $nonce = $session->pull(self::SESSION_KEY);

        $parts = explode('.', $state, 2);
        if (count($parts) !== 2 || $parts[0] === '' || ! is_string($nonce)) {
            throw $fail();
        }

        [$token, $signature] = $parts;

        if (! hash_equals($this->sign($token, $tenantId, (int) $user->getKey()), $signature)) {
            throw $fail();
        }

        $row = MetaOauthState::query()
            ->where('state_hash', hash('sha256', $token))
            ->where('user_id', $user->getKey())
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $row || ! hash_equals($row->nonce_hash, hash('sha256', $nonce))) {
            throw $fail();
        }

        // Atomic single use: of two concurrent callbacks only one flips used_at.
        $claimed = MetaOauthState::query()->whereKey($row->getKey())->whereNull('used_at')->update(['used_at' => now()]);

        if ($claimed !== 1) {
            throw $fail();
        }

        return $row;
    }

    public function prune(): int
    {
        return MetaOauthState::query()->where('expires_at', '<', now()->subDay())->delete();
    }

    private function sign(string $token, int $tenantId, int $userId): string
    {
        return hash_hmac('sha256', $token.'|'.$tenantId.'|'.$userId, (string) config('app.key'));
    }
}
