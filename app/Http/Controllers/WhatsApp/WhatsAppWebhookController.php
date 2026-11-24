<?php

namespace App\Http\Controllers\WhatsApp;

use App\Actions\WhatsApp\ReceiveWhatsAppWebhook;
use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppLog;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public, unauthenticated endpoints called by Meta for WhatsApp. No session, no CSRF: authenticity is the HMAC
 * signature over the raw body, made with the platform Meta app secret.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $expected = (string) config('whatsapp.webhook_verify_token');
        $mode = (string) $request->query('hub_mode', '');
        $token = (string) $request->query('hub_verify_token', '');
        $challenge = (string) $request->query('hub_challenge', '');

        if ($expected === '' || $mode !== 'subscribe' || ! hash_equals($expected, $token) || preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $challenge) !== 1) {
            $this->rejected($request, 'handshake');

            return response('Forbidden', 403);
        }

        return response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, ReceiveWhatsAppWebhook $receive): Response
    {
        $secret = (string) config('meta.app_secret');
        if ($secret === '') {
            WhatsAppLog::error('webhook.not_configured');

            return response('Not configured', 503);
        }

        $max = (int) config('whatsapp.webhook_max_bytes', 262144);
        $raw = $request->getContent();
        if ((int) $request->header('Content-Length') > $max || strlen($raw) > $max) {
            return response('Payload too large', 413);
        }

        $expected = 'sha256='.hash_hmac('sha256', $raw, $secret);
        if (! hash_equals($expected, (string) $request->header('X-Hub-Signature-256', ''))) {
            $this->rejected($request, 'signature');

            return response('Invalid signature', 403);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response('Bad request', 400);
        }

        // A throw here (e.g. database down) yields a 500, so Meta redelivers: we only answer 200 once stored.
        $stats = $receive($payload);
        WhatsAppLog::info('webhook.accepted', $stats);

        return response('EVENT_RECEIVED', 200, ['Content-Type' => 'text/plain']);
    }

    private function rejected(Request $request, string $reason): void
    {
        WhatsAppLog::warning('webhook.rejected', ['reason' => $reason, 'ip' => $request->ip()]);
        RateLimiter::attempt('wa-webhook-reject-audit|'.$request->ip(), 1, fn () => AuditLogger::record('whatsapp.webhook.rejected', null, ['reason' => $reason]), 60);
    }
}
