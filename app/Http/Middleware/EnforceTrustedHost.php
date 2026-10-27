<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Password-reset and invitation links are built from the request host. Rejecting unknown Host headers
 * prevents an attacker from making the app e-mail a victim a link that points at the attacker's domain.
 */
class EnforceTrustedHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('crm.enforce_host')) {
            $allowed = array_filter([
                parse_url((string) config('app.url'), PHP_URL_HOST),
                ...config('crm.allowed_hosts', []),
            ]);

            abort_unless(in_array(strtolower($request->getHost()), array_map('strtolower', $allowed), true), 400, 'Invalid host.');
        }

        return $next($request);
    }
}
