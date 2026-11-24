<?php

/*
| WhatsApp Cloud API. Uses the same platform Meta app as Lead Ads (META_APP_ID / META_APP_SECRET / META_GRAPH_VERSION):
| webhook signatures are verified with META_APP_SECRET. Each workspace connects its own WhatsApp number by entering a
| System User access token (with whatsapp_business_messaging + whatsapp_business_management) and the number's ids.
|
| Verified against MOCKED responses only. See docs/PHASE_4_WHATSAPP.md.
*/
return [
    // Webhook handshake token entered in the Meta app's WhatsApp > Configuration. Required; no default.
    'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),

    'webhook_max_bytes' => (int) env('WHATSAPP_WEBHOOK_MAX_BYTES', 262144),
    'webhook_max_items' => (int) env('WHATSAPP_WEBHOOK_MAX_ITEMS', 200),

    // Meta's customer-service window: free-form messages are allowed only this long after the contact last wrote.
    'service_window_hours' => 24,

    // Body limit for free-form text (Meta's documented maximum is 4096 characters).
    'max_text_length' => 4096,

    // Inbound keywords (case-insensitive, whole message) that record an opt-out / opt-in.
    'opt_out_keywords' => ['stop', 'unsubscribe', 'stop all'],
    'opt_in_keywords' => ['start', 'subscribe'],

    'job' => [
        'tries' => (int) env('WHATSAPP_JOB_TRIES', 5),
        'backoff' => [10, 60, 300, 900], // seconds
    ],

    // Inbox polling interval for the browser (seconds).
    'inbox_poll_seconds' => (int) env('WHATSAPP_INBOX_POLL_SECONDS', 10),
];
