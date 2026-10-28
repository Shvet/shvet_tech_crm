<?php

namespace App\Services\Meta;

/** Reads Meta settings and reports, by NAME only, what is missing. Never returns secret values to callers that display them. */
class MetaConfig
{
    /** @return list<string> env names that must be set before the integration can be used */
    public static function missing(): array
    {
        $required = [
            'META_APP_ID' => 'app_id',
            'META_APP_SECRET' => 'app_secret',
            'META_WEBHOOK_VERIFY_TOKEN' => 'webhook_verify_token',
            'META_GRAPH_VERSION' => 'graph_version',
        ];

        return array_keys(array_filter($required, fn (string $key) => blank(config("meta.$key"))));
    }

    public static function isConfigured(): bool
    {
        return self::missing() === [];
    }

    /** True once ANY Meta credential is set (used by preflight to flag half-configured deployments). */
    public static function isPartiallyConfigured(): bool
    {
        return filled(config('meta.app_id')) || filled(config('meta.app_secret')) || filled(config('meta.webhook_verify_token'));
    }

    public static function redirectUri(): string
    {
        return (string) (config('meta.oauth_redirect_uri') ?: rtrim((string) config('app.url'), '/').'/integrations/meta/callback');
    }

    public static function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/webhooks/meta';
    }

    /** @return list<string> */
    public static function requiredPermissions(): array
    {
        return array_values(config('meta.required_permissions', []));
    }
}
