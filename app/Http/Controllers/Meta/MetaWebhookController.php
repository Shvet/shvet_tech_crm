<?php

namespace App\Http\Controllers\Meta;

use App\Actions\Meta\ReceiveMetaWebhook;
use App\Http\Controllers\Controller;
use App\Services\Meta\MetaLog;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public, unauthenticated endpoints called by Meta. No session, no CSRF: authenticity is the HMAC signature.
 */
class MetaWebhookController extends Controller
{
    /** Subscription handshake (GET). PHP turns "hub.mode" into "hub_mode". */
    public function verify(Request $request): Response
    {
        $expected = (string) config('meta.webhook_verify_token');
        $mode = (string) $request->query('hub_mode', '');
        $token = (string) $request->query('hub_verify_token', '');
        $challenge = (string) $request->query('hub_challenge', '');

        if ($expected === '' || $mode !== 'subscribe' || ! hash_equals($expected, $token) || preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $challenge) !== 1) {
            $this->rejected($request, 'handshake');

            return response('Forbidden', 403);
        }

        return response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    /** Event delivery (POST). */
    public function receive(Request $request, ReceiveMetaWebhook $receive): Response
    {
        $secret = (string) config('meta.app_secret');
        if ($secret === '') {
            MetaLog::error('webhook.not_configured');

            return response('Not configured', 503);
        }

        $max = (int) config('meta.webhook_max_bytes', 262144);
        if ((int) $request->header('Content-Length', 0) > $max) {
            return response('Payload too large', 413);
        }

        $raw = $request->getContent();
        if (strlen($raw) > $max) {
            return response('Payload too large', 413);
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $raw, $secret);

        if (! hash_equals($expected, $header)) {
            $this->rejected($request, 'signature');

            return response('Invalid signature', 403);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response('Bad request', 400);
        }

        // A throw here (e.g. database down) yields a 500, so Meta redelivers: we only ever 200 once events are stored.
        $stats = $receive($payload);
        MetaLog::info('webhook.accepted', $stats);

        return response('EVENT_RECEIVED', 200, ['Content-Type' => 'text/plain']);
    }

    private function rejected(Request $request, string $reason): void
    {
        MetaLog::warning('webhook.rejected', ['reason' => $reason, 'ip' => $request->ip()]);

        // One audit row per minute per IP, so a flood of bad signatures cannot flood the audit log.
        RateLimiter::attempt('meta-webhook-reject-audit|'.$request->ip(), 1, fn () => AuditLogger::record('meta.webhook.rejected', null, ['reason' => $reason]), 60);
    }
}
