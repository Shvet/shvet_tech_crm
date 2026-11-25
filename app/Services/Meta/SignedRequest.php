<?php

namespace App\Services\Meta;

/**
 * Parses the `signed_request` Meta posts to the Deauthorize and Data Deletion callbacks:
 * "<base64url HMAC-SHA256 signature>.<base64url JSON payload>", signed with the app secret.
 */
class SignedRequest
{
    /** @return array<string, mixed>|null The payload, or null when it is malformed or the signature does not match. */
    public static function parse(string $signedRequest, string $appSecret): ?array
    {
        if ($appSecret === '' || strlen($signedRequest) > 4096 || substr_count($signedRequest, '.') !== 1) {
            return null;
        }

        [$encodedSig, $encodedPayload] = explode('.', $signedRequest, 2);
        $signature = self::base64UrlDecode($encodedSig);
        $json = self::base64UrlDecode($encodedPayload);
        if ($signature === null || $json === null) {
            return null;
        }

        $payload = json_decode($json, true);
        if (! is_array($payload) || strtoupper((string) ($payload['algorithm'] ?? '')) !== 'HMAC-SHA256') {
            return null;
        }

        // Meta signs the still-encoded payload segment.
        if (! hash_equals(hash_hmac('sha256', $encodedPayload, $appSecret, true), $signature)) {
            return null;
        }

        return $payload;
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_\-]+={0,2}$/', $value) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
