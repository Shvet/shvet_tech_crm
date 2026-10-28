<?php

namespace App\Services\Meta;

use App\Exceptions\MetaApiException;
use App\Exceptions\MetaConnectionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The ONLY place that talks to Meta's Graph API.
 *
 * - Version comes from configuration; there is no default (MetaConfig::missing()).
 * - Access tokens travel in the Authorization header, never in URLs; every call with a user/page token also sends
 *   appsecret_proof. The one unavoidable exception is the OAuth code exchange, whose parameters Meta documents as
 *   query parameters; those URLs are never logged and exceptions here never include them.
 * - Every failure becomes a MetaApiException (category + sanitized summary): no tokens, URLs or payloads leak.
 *
 * NOTE: endpoint behavior below follows Meta's documented API as understood at authoring time and has been verified
 * ONLY against mocked responses. See docs/PHASE_3_META_LEAD_ADS.md.
 */
class GraphClient
{
    private const PAGE_LIMIT = 100;

    private const MAX_PAGES = 5;

    /** Dialog URL the admin's browser is sent to. No secrets: client_id, our fixed redirect URI, scopes, state. */
    public function authorizationUrl(string $state): string
    {
        return config('meta.oauth_dialog_base_url').'/'.$this->version().'/dialog/oauth?'.http_build_query([
            'client_id' => config('meta.app_id'),
            'redirect_uri' => MetaConfig::redirectUri(),
            'state' => $state,
            'scope' => implode(',', config('meta.oauth_scopes')),
            'response_type' => 'code',
        ]);
    }

    /** @return array{access_token: string, expires_in: ?int} */
    public function exchangeCode(string $code): array
    {
        $data = $this->call('GET', '/oauth/access_token', [
            'client_id' => config('meta.app_id'),
            'client_secret' => config('meta.app_secret'),
            'redirect_uri' => MetaConfig::redirectUri(), // our configured URI, never the request's
            'code' => $code,
        ]);

        return $this->tokenFrom($data);
    }

    /** @return array{access_token: string, expires_in: ?int} */
    public function longLivedToken(string $shortLivedToken): array
    {
        $data = $this->call('GET', '/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('meta.app_id'),
            'client_secret' => config('meta.app_secret'),
            'fb_exchange_token' => $shortLivedToken,
        ]);

        return $this->tokenFrom($data);
    }

    /** @return array{is_valid: bool, scopes: list<string>, expires_at: ?int, data_access_expires_at: ?int, user_id: ?string} */
    public function debugToken(string $token): array
    {
        $data = $this->call('GET', '/debug_token', ['input_token' => $token], appToken: true)['data'] ?? [];

        return [
            'is_valid' => (bool) ($data['is_valid'] ?? false),
            'scopes' => array_values(array_map('strval', $data['scopes'] ?? [])),
            'expires_at' => isset($data['expires_at']) && (int) $data['expires_at'] > 0 ? (int) $data['expires_at'] : null,
            'data_access_expires_at' => isset($data['data_access_expires_at']) && (int) $data['data_access_expires_at'] > 0 ? (int) $data['data_access_expires_at'] : null,
            'user_id' => isset($data['user_id']) ? (string) $data['user_id'] : null,
        ];
    }

    /** @return array{id: string, name: ?string} */
    public function me(string $userToken): array
    {
        $data = $this->call('GET', '/me', ['fields' => 'id,name'], $userToken);

        return ['id' => (string) ($data['id'] ?? ''), 'name' => $data['name'] ?? null];
    }

    /** Pages the user manages, each WITH its own Page access token. @return list<array{id: string, name: string, access_token: string}> */
    public function pages(string $userToken): array
    {
        $out = [];
        foreach ($this->paginate('/me/accounts', ['fields' => 'id,name,access_token', 'limit' => self::PAGE_LIMIT], $userToken) as $row) {
            if (isset($row['id'], $row['access_token'])) {
                $out[] = ['id' => (string) $row['id'], 'name' => (string) ($row['name'] ?? $row['id']), 'access_token' => (string) $row['access_token']];
            }
        }

        return $out;
    }

    public function subscribePage(string $pageId, string $pageToken): void
    {
        $this->call('POST', "/{$pageId}/subscribed_apps", ['subscribed_fields' => 'leadgen'], $pageToken);
    }

    public function unsubscribePage(string $pageId, string $pageToken): void
    {
        $this->call('DELETE', "/{$pageId}/subscribed_apps", [], $pageToken);
    }

    /** @return list<array{id: string, name: string, status: ?string}> */
    public function leadForms(string $pageId, string $pageToken): array
    {
        $out = [];
        foreach ($this->paginate("/{$pageId}/leadgen_forms", ['fields' => 'id,name,status', 'limit' => self::PAGE_LIMIT], $pageToken) as $row) {
            if (isset($row['id'])) {
                $out[] = ['id' => (string) $row['id'], 'name' => (string) ($row['name'] ?? $row['id']), 'status' => isset($row['status']) ? (string) $row['status'] : null];
            }
        }

        return $out;
    }

    /** The full lead (answers included) — the webhook only ever carries IDs. @return array<string, mixed> */
    public function lead(string $leadgenId, string $pageToken): array
    {
        return $this->call('GET', "/{$leadgenId}", [
            'fields' => 'id,created_time,field_data,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,form_id,is_organic,platform',
        ], $pageToken);
    }

    // ------------------------------------------------------------------------------------------------ internals

    private function version(): string
    {
        $version = (string) config('meta.graph_version');
        if ($version === '') {
            throw new MetaConnectionException('Meta Graph API version is not configured (set META_GRAPH_VERSION).');
        }

        return trim($version, '/');
    }

    /** @param  array<string, mixed>  $params */
    private function call(string $method, string $path, array $params = [], ?string $token = null, bool $appToken = false): array
    {
        $request = $this->http();

        if ($appToken) {
            $token = config('meta.app_id').'|'.config('meta.app_secret'); // app access token
        } elseif ($token !== null) {
            $params['appsecret_proof'] = hash_hmac('sha256', $token, (string) config('meta.app_secret'));
        }
        if ($token !== null) {
            $request = $request->withToken($token);
        }

        $url = config('meta.graph_base_url').'/'.$this->version().$path;

        try {
            $response = match ($method) {
                'GET' => $request->get($url, $params),
                'POST' => $request->asForm()->post($url, $params),
                'DELETE' => $request->delete($url, $params),
            };
        } catch (ConnectionException) {
            throw new MetaApiException(MetaApiException::TRANSIENT);
        } catch (\Throwable) {
            throw new MetaApiException(MetaApiException::UNKNOWN);
        }

        return $this->handle($response);
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout((int) config('meta.http.connect_timeout'))
            ->timeout((int) config('meta.http.timeout'));
    }

    /** @return array<string, mixed> */
    private function handle(Response $response): array
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->successful() && ! isset($body['error'])) {
            return $body;
        }

        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $code = isset($error['code']) ? (int) $error['code'] : null;
        $subcode = isset($error['error_subcode']) ? (int) $error['error_subcode'] : null;

        throw new MetaApiException(
            $this->classify($response->status(), $code),
            $response->status() ?: null,
            $code,
            $subcode,
            $this->retryAfter($response),
            isset($error['fbtrace_id']) ? (string) $error['fbtrace_id'] : null,
        );
    }

    private function classify(int $status, ?int $code): string
    {
        return match (true) {
            $code === 190 => MetaApiException::AUTH,
            in_array($code, [4, 17, 32, 341, 368, 613], true), ($code !== null && $code >= 80000 && $code <= 80099), $status === 429 => MetaApiException::RATE_LIMIT,
            $code === 10, ($code !== null && $code >= 200 && $code <= 299) => MetaApiException::PERMISSION,
            in_array($code, [1, 2], true), $status >= 500 => MetaApiException::TRANSIENT,
            $code === 803, $status === 404 => MetaApiException::NOT_FOUND,
            $code === 100 => MetaApiException::INVALID,
            $status === 401 => MetaApiException::AUTH,
            $status === 403 => MetaApiException::PERMISSION,
            default => MetaApiException::UNKNOWN,
        };
    }

    /** Honor Retry-After, or Meta's business-use-case "estimated_time_to_regain_access" (minutes). */
    private function retryAfter(Response $response): ?int
    {
        $retry = $response->header('Retry-After');
        if (is_numeric($retry) && (int) $retry > 0) {
            return min((int) $retry, 86400);
        }

        foreach (['X-Business-Use-Case-Usage', 'X-App-Usage', 'X-Page-Usage'] as $name) {
            $decoded = json_decode((string) $response->header($name), true);
            if (! is_array($decoded)) {
                continue;
            }
            $minutes = $this->findKey($decoded, 'estimated_time_to_regain_access');
            if (is_numeric($minutes) && (int) $minutes > 0) {
                return min((int) $minutes * 60, 86400);
            }
        }

        return null;
    }

    private function findKey(array $haystack, string $key): mixed
    {
        foreach ($haystack as $k => $v) {
            if ($k === $key) {
                return $v;
            }
            if (is_array($v) && ($found = $this->findKey($v, $key)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @return iterable<array<string, mixed>> bounded: at most MAX_PAGES pages of results */
    private function paginate(string $path, array $params, string $token): iterable
    {
        $after = null;
        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            $data = $this->call('GET', $path, $after ? [...$params, 'after' => $after] : $params, $token);
            foreach ($data['data'] ?? [] as $row) {
                if (is_array($row)) {
                    yield $row;
                }
            }
            $after = $data['paging']['cursors']['after'] ?? null;
            if (! $after || ! isset($data['paging']['next'])) {
                return;
            }
        }
    }

    /** @return array{access_token: string, expires_in: ?int} */
    private function tokenFrom(array $data): array
    {
        if (empty($data['access_token']) || ! is_string($data['access_token'])) {
            throw new MetaApiException(MetaApiException::UNKNOWN);
        }

        return ['access_token' => $data['access_token'], 'expires_in' => isset($data['expires_in']) ? (int) $data['expires_in'] : null];
    }
}
