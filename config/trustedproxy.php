<?php

/*
| Proxies whose X-Forwarded-* headers are trusted (comma separated IPs/CIDRs in TRUSTED_PROXIES; empty = trust none).
|
| This lives in config on purpose: Laravel's TrustProxies middleware reads `trustedproxy.proxies` per request, and
| env() returns null outside config files once `php artisan config:cache` has run (it did in bootstrap/app.php, which
| silently disabled proxy trust in production and made every client share the proxy's IP).
*/
return [
    'proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
];
