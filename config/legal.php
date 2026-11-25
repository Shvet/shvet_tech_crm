<?php

/*
| Public legal pages (privacy policy, terms of service, data deletion instructions) required by Meta App Review for
| Facebook Login, Lead Ads, Instagram and WhatsApp. The wording lives in resources/views/legal; the facts that differ
| per deployment live here so they can be set without touching the templates. Have counsel review before going live.
*/
return [
    // The legal entity that operates this CRM (the "data controller/processor" named in the policies).
    'company_name' => env('LEGAL_COMPANY_NAME', 'Shvet Technologies'),

    // Where people send privacy and deletion requests. Meta reviewers check that this is a real, monitored inbox.
    'contact_email' => env('LEGAL_CONTACT_EMAIL'),

    // Optional postal address shown in the policies.
    'address' => env('LEGAL_ADDRESS'),

    // Date the current text took effect (shown as "Last updated").
    'effective_date' => env('LEGAL_EFFECTIVE_DATE', '2026-11-25'),

    // Law and courts that govern the terms.
    'governing_law' => env('LEGAL_GOVERNING_LAW', 'India'),

    // How long a deletion request takes at most, promised on the data deletion page.
    'deletion_days' => (int) env('LEGAL_DELETION_DAYS', 30),
];
