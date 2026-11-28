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

    /*
    | Default region used to interpret phone numbers typed without a country code (ISO 3166-1 alpha-2).
    */
    'default_region' => env('CRM_DEFAULT_REGION', 'IN'),

    /*
    | List sizes.
    */
    'leads_per_page' => (int) env('CRM_LEADS_PER_PAGE', 20),
    'pipeline_cards_per_stage' => (int) env('CRM_PIPELINE_CARDS_PER_STAGE', 30),

    /*
    | CSV export / import and bulk actions.
    */
    'export_max_rows' => (int) env('CRM_EXPORT_MAX_ROWS', 50000),
    'import_max_rows' => (int) env('CRM_IMPORT_MAX_ROWS', 2000),
    'import_max_kb' => (int) env('CRM_IMPORT_MAX_KB', 2048),
    'bulk_max_leads' => 100,

    /*
    | Follow-up reminder digest: sent once a day, at this local hour (tenant timezone), to members with open
    | follow-ups that are overdue or due today. Needs the scheduler (`schedule:run` every minute).
    */
    'reminder_hour' => (int) env('CRM_REMINDER_HOUR', 8),
];
