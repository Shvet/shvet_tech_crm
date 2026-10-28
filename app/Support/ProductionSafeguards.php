<?php

namespace App\Support;

use App\Services\Meta\MetaConfig;

/**
 * Configuration that must hold before this application serves real tenants. `critical()` problems make
 * a production HTTP boot fail; `warnings()` are reported by `php artisan crm:preflight`.
 */
class ProductionSafeguards
{
    /** @return list<string> */
    public static function critical(): array
    {
        $problems = [];

        if (config('app.debug')) {
            $problems[] = 'APP_DEBUG must be false';
        }
        if (empty(config('app.key'))) {
            $problems[] = 'APP_KEY is not set';
        }
        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $problems[] = 'APP_URL must be an https:// URL';
        }
        if (config('session.secure') !== true) {
            $problems[] = 'SESSION_SECURE_COOKIE must be true';
        }
        if (config('session.http_only') !== true) {
            $problems[] = 'session cookies must be HttpOnly';
        }
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $problems[] = 'MAIL_MAILER must deliver real mail (log/array would write password-reset links to disk)';
        }
        if (! config('crm.enforce_host')) {
            $problems[] = 'CRM_ENFORCE_HOST must be enabled (Host-header poisoning of reset links)';
        }
        if (in_array(config('session.driver'), ['array', 'cookie'], true)) {
            $problems[] = 'SESSION_DRIVER must be server-side (database or redis)';
        }

        // Meta is optional, but a half-configured integration must not go live silently.
        if (MetaConfig::isPartiallyConfigured()) {
            if (MetaConfig::missing() !== []) {
                $problems[] = 'Meta integration is half-configured; missing: '.implode(', ', MetaConfig::missing());
            }
            if (strlen((string) config('meta.webhook_verify_token')) < 24) {
                $problems[] = 'META_WEBHOOK_VERIFY_TOKEN must be at least 24 random characters';
            }
            if (! str_starts_with(MetaConfig::redirectUri(), 'https://')) {
                $problems[] = 'The Meta OAuth redirect URI must be https://';
            }
        }

        return $problems;
    }

    /** @return list<string> */
    public static function warnings(): array
    {
        $warnings = [];

        if ((string) env('TRUSTED_PROXIES', '') === '') {
            $warnings[] = 'TRUSTED_PROXIES is empty: behind a proxy/load balancer every client shares one IP (rate limits, audit IPs)';
        }
        if (config('session.encrypt') !== true) {
            $warnings[] = 'SESSION_ENCRYPT is off';
        }
        if (in_array(config('queue.default'), ['sync'], true)) {
            $warnings[] = 'QUEUE_CONNECTION=sync: jobs run inside web requests';
        }
        if (! in_array(config('queue.default'), ['redis', 'sync'], true)) {
            $warnings[] = 'Queue driver is not Redis (database queue works but is slower under load)';
        }
        if (config('database.connections.'.config('database.default').'.username') === 'root') {
            $warnings[] = 'Application connects to the database as root; use a least-privilege account';
        }
        if (config('logging.channels.single.level') === 'debug' || env('LOG_LEVEL') === 'debug') {
            $warnings[] = 'LOG_LEVEL=debug in production is noisy; use info or warning';
        }
        if (config('permission.teams') !== true) {
            $warnings[] = 'spatie permission teams mode is off: roles would not be tenant-scoped';
        }
        if (MetaConfig::isConfigured() && config('queue.default') === 'sync') {
            $warnings[] = 'Meta lead imports need a real queue worker (QUEUE_CONNECTION=sync would import inside the webhook request)';
        }

        return $warnings;
    }
}
