<?php

namespace App\Services\WhatsApp;

use App\Exceptions\MetaApiException;
use App\Exceptions\MetaConnectionException;
use App\Services\Meta\GraphClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The ONLY place that talks to the WhatsApp Cloud API (graph.facebook.com, same versioned host as Lead Ads).
 *
 * - The workspace's System User token travels in the Authorization header, plus appsecret_proof; never in a URL.
 * - Every failure becomes a MetaApiException (category + codes only): no token, URL, phone number or message text.
 *
 * NOTE: endpoints and payloads follow Meta's documented Cloud API as understood at authoring time and are verified
 * ONLY against mocked responses. See docs/PHASE_4_WHATSAPP.md.
 */
class CloudApiClient
{
    /** WhatsApp error codes that mean "slow down". */
    private const RATE_LIMIT_CODES = [130429, 131048, 131056, 80007];

    /** WhatsApp error codes that are temporary on Meta's side. */
    private const TRANSIENT_CODES = [131000, 131016, 133004];

    /** @return array{display_phone_number: ?string, verified_name: ?string} */
    public function phoneNumber(string $phoneNumberId, string $token): array
    {
        $data = $this->call('GET', "/{$phoneNumberId}", ['fields' => 'display_phone_number,verified_name'], $token);

        return [
            'display_phone_number' => isset($data['display_phone_number']) ? (string) $data['display_phone_number'] : null,
            'verified_name' => isset($data['verified_name']) ? (string) $data['verified_name'] : null,
        ];
    }

    /** Ids of the phone numbers that belong to the WhatsApp Business Account. @return list<string> */
    public function phoneNumberIds(string $wabaId, string $token): array
    {
        $data = $this->call('GET', "/{$wabaId}/phone_numbers", ['fields' => 'id', 'limit' => 100], $token);

        return array_values(array_filter(array_map(
            fn ($row) => is_array($row) && isset($row['id']) ? (string) $row['id'] : null,
            is_array($data['data'] ?? null) ? $data['data'] : [],
        )));
    }

    /** Subscribes our Meta app to the WABA's webhooks (messages + statuses). */
    public function subscribeApp(string $wabaId, string $token): void
    {
        $this->call('POST', "/{$wabaId}/subscribed_apps", [], $token);
    }

    /** @return list<array{name: string, language: string, category: ?string, status: string, components: array<int, mixed>}> */
    public function templates(string $wabaId, string $token): array
    {
        $out = [];
        $after = null;
        for ($page = 0; $page < 10; $page++) {
            $params = ['fields' => 'name,language,status,category,components', 'limit' => 100] + ($after ? ['after' => $after] : []);
            $data = $this->call('GET', "/{$wabaId}/message_templates", $params, $token);
            foreach (is_array($data['data'] ?? null) ? $data['data'] : [] as $row) {
                if (is_array($row) && isset($row['name'], $row['language'], $row['status'])) {
                    $out[] = [
                        'name' => (string) $row['name'],
                        'language' => (string) $row['language'],
                        'category' => isset($row['category']) ? (string) $row['category'] : null,
                        'status' => (string) $row['status'],
                        'components' => is_array($row['components'] ?? null) ? $row['components'] : [],
                    ];
                }
            }
            $after = $data['paging']['cursors']['after'] ?? null;
            if (! $after || ! isset($data['paging']['next'])) {
                break;
            }
        }

        return $out;
    }

    /** @return string the new message's wamid */
    public function sendText(string $phoneNumberId, string $token, string $to, string $body): string
    {
        return $this->send($phoneNumberId, $token, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ]);
    }

    /** @param  list<string>  $bodyParams @return string the new message's wamid */
    public function sendTemplate(string $phoneNumberId, string $token, string $to, string $name, string $language, array $bodyParams): string
    {
        $template = ['name' => $name, 'language' => ['code' => $language]];
        if ($bodyParams !== []) {
            $template['components'] = [[
                'type' => 'body',
                'parameters' => array_map(fn (string $text) => ['type' => 'text', 'text' => $text], $bodyParams),
            ]];
        }

        return $this->send($phoneNumberId, $token, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => $template,
        ]);
    }

    // ------------------------------------------------------------------------------------------------ internals

    /** @param  array<string, mixed>  $payload */
    private function send(string $phoneNumberId, string $token, array $payload): string
    {
        $data = $this->call('POST', "/{$phoneNumberId}/messages", $payload, $token, json: true);
        $wamid = $data['messages'][0]['id'] ?? null;
        if (! is_string($wamid) || $wamid === '') {
            throw new MetaApiException(MetaApiException::UNKNOWN);
        }

        return $wamid;
    }

    private function version(): string
    {
        $version = (string) config('meta.graph_version');
        if ($version === '') {
            throw new MetaConnectionException('Meta Graph API version is not configured (set META_GRAPH_VERSION).');
        }

        return trim($version, '/');
    }

    /** @param  array<string, mixed>  $params @return array<string, mixed> */
    private function call(string $method, string $path, array $params, string $token, bool $json = false): array
    {
        $secret = (string) config('meta.app_secret');
        $proof = $secret !== '' ? ['appsecret_proof' => hash_hmac('sha256', $token, $secret)] : [];
        $url = config('meta.graph_base_url').'/'.$this->version().$path;
        $request = $this->http()->withToken($token);

        try {
            $response = match (true) {
                $method === 'GET' => $request->get($url, $params + $proof),
                $json => $request->withQueryParameters($proof)->asJson()->post($url, $params),
                default => $request->asForm()->post($url, $params + $proof),
            };
        } catch (ConnectionException) {
            throw new MetaApiException(MetaApiException::TRANSIENT);
        } catch (\Throwable) {
            throw new MetaApiException(MetaApiException::UNKNOWN);
        }

        $body = $response->json();
        if ($response->successful() && is_array($body) && ! isset($body['error'])) {
            return $body;
        }

        throw $this->error($response);
    }

    private function error(Response $response): MetaApiException
    {
        $e = GraphClient::errorFrom($response);
        $code = $e->metaCode;

        $category = match (true) {
            $code === null => $e->category,
            in_array($code, self::RATE_LIMIT_CODES, true) => MetaApiException::RATE_LIMIT,
            in_array($code, self::TRANSIENT_CODES, true) => MetaApiException::TRANSIENT,
            // 131xxx / 132xxx / 133xxx / 135xxx: message, template or number problems. Retrying will not help.
            $code >= 131000 && $code <= 135999 => MetaApiException::INVALID,
            default => $e->category,
        };

        return $category === $e->category ? $e
            : new MetaApiException($category, $e->httpStatus, $code, $e->metaSubcode, $e->retryAfterSeconds, $e->traceId);
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout((int) config('meta.http.connect_timeout', 5))
            ->timeout((int) config('meta.http.timeout', 20));
    }
}
