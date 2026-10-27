<?php

return [
    /*
    | Invitations
    */
    'invitation_ttl_days' => (int) env('CRM_INVITATION_TTL_DAYS', 7),
    'max_pending_invitations' => (int) env('CRM_MAX_PENDING_INVITATIONS', 100),
    'invitations_per_hour' => (int) env('CRM_INVITATIONS_PER_HOUR', 20),

    /*
    | Reject requests whose Host header is not one of the allowed hosts (prevents password-reset link
    | poisoning). Defaults to on in production. CRM_ALLOWED_HOSTS: extra comma-separated hosts.
    */
    'enforce_host' => (bool) env('CRM_ENFORCE_HOST', env('APP_ENV') === 'production'),
    'allowed_hosts' => array_filter(array_map('trim', explode(',', (string) env('CRM_ALLOWED_HOSTS', '')))),
];
