<?php

namespace App\Support;

/** Strips secrets and personal data from anything headed for logs or audit metadata. */
class Redactor
{
    public const MASK = '[redacted]';

    /** Keys whose values are always removed (case-insensitive, substring match). */
    private const SENSITIVE_KEYS = [
        'password', 'secret', 'token', 'authorization', 'api_key', 'apikey', 'appsecret_proof',
        'access_token', 'verify_token', 'cookie', 'credentials', 'message_body', 'body',
    ];

    public static function redactArray(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $out[$key] = self::MASK;
            } elseif (is_array($value)) {
                $out[$key] = self::redactArray($value);
            } elseif (is_string($value)) {
                $out[$key] = self::redactString($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public static function redactString(string $value): string
    {
        $patterns = [
            // Bearer / Basic credentials
            '/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]+=*/i' => '$1 '.self::MASK,
            // access_token=..., token: ..., secret=..., appsecret_proof=... in URLs / text
            '/\b((?:access_)?token|secret|app_?secret|appsecret_proof|verify_token|password|api_?key)(["\']?\s*[=:]\s*["\']?)[^\s&"\',;]+/i' => '$1$2'.self::MASK,
            // password-reset / signed-URL tokens in paths
            '#(reset-password/)[A-Za-z0-9_\-]{16,}#' => '$1'.self::MASK,
            // Meta/Graph style long tokens
            '/\bEA[A-Za-z0-9]{40,}\b/' => self::MASK,
            // email addresses
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '[email]',
            // phone numbers (7+ digits, optional + and separators)
            '/(?<![\w])\+?\d[\d\s\-().]{6,}\d(?![\w])/' => '[phone]',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $value) ?? self::MASK;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
