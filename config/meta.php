<?php

/*
| Meta (Facebook / Instagram) Lead Ads integration. One platform Meta app serves every tenant; each tenant
| authorizes its own Pages through OAuth. Nothing here has a "safe default" for credentials or the Graph version:
| unset means the integration is disabled and says why (see App\Services\Meta\MetaConfig).
*/
return [
    'app_id' => env('META_APP_ID'),
    'app_secret' => env('META_APP_SECRET'),
    'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),

    // REQUIRED, deliberately without a default. Set from Meta's changelog (e.g. "v25.0") and re-check before deprecations.
    'graph_version' => env('META_GRAPH_VERSION'),

    // Overridable ONLY so tests/dev can point at a mock server. Production must stay https (crm:preflight enforces it).
    'graph_base_url' => rtrim((string) env('META_GRAPH_BASE_URL', 'https://graph.facebook.com'), '/'),
    'oauth_dialog_base_url' => rtrim((string) env('META_OAUTH_DIALOG_BASE_URL', 'https://www.facebook.com'), '/'),

    // Exact URI registered in the Meta app. If unset it is derived from APP_URL (trusted config) - never from the request.
    'oauth_redirect_uri' => env('META_OAUTH_REDIRECT_URI'),

    // Requested at login, and (separately) REQUIRED to be actually granted. See docs/PHASE_3_META_LEAD_ADS.md section 1.2.
    'oauth_scopes' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'META_OAUTH_SCOPES',
        'pages_show_list,pages_read_engagement,pages_manage_metadata,leads_retrieval,pages_manage_ads',
    ))))),
    'required_permissions' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'META_REQUIRED_PERMISSIONS',
        'pages_show_list,pages_manage_metadata,leads_retrieval,pages_manage_ads',
    ))))),

    'oauth_state_ttl_minutes' => (int) env('META_OAUTH_STATE_TTL_MINUTES', 10),
    'pending_assets_ttl_minutes' => (int) env('META_PENDING_ASSETS_TTL_MINUTES', 30),

    // Webhook hardening.
    'webhook_max_bytes' => (int) env('META_WEBHOOK_MAX_BYTES', 262144),
    'webhook_max_changes' => (int) env('META_WEBHOOK_MAX_CHANGES', 100),

    // Outbound HTTP (seconds).
    'http' => [
        'connect_timeout' => (int) env('META_HTTP_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('META_HTTP_TIMEOUT', 20),
    ],

    // Queue processing.
    'job' => [
        'tries' => (int) env('META_JOB_TRIES', 6),
        'backoff' => [30, 120, 600, 1800, 3600], // seconds, before jitter
        'max_release_seconds' => 3600,
    ],
    'recovery_stale_minutes' => (int) env('META_RECOVERY_STALE_MINUTES', 5),
    'processing_stuck_minutes' => (int) env('META_PROCESSING_STUCK_MINUTES', 15),

    // Privacy: terminal import records hold IDs and outcomes only (never lead answers); they are pruned after this.
    'retention_days' => (int) env('META_RETENTION_DAYS', 90),
];
