<?php

namespace App\Actions\Meta;

use App\Enums\MetaConnectionStatus;
use App\Exceptions\MetaConnectionException;
use App\Models\MetaConnection;
use App\Models\MetaPage;
use App\Models\User;
use App\Services\Meta\GraphClient;
use App\Services\Meta\MetaConfig;
use App\Services\Meta\MetaLog;
use App\Services\Meta\OAuthStateService;
use App\Support\AuditLogger;
use App\Support\Facades\Tenancy;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;

/**
 * OAuth callback. Order matters: validate state (tenant+user+session bound, single use) BEFORE touching the code.
 * The short-lived user token is exchanged for a long-lived one, inspected, used once to list Pages, then DISCARDED:
 * only per-Page tokens (encrypted) are ever kept. The Pages the admin may choose from wait in an encrypted blob.
 */
class CompleteMetaConnection
{
    public function __construct(private OAuthStateService $states, private GraphClient $graph) {}

    public function __invoke(User $user, string $state, string $code, Session $session): MetaConnection
    {
        $this->states->consume($state, $user, $session);

        $short = $this->graph->exchangeCode($code);
        $long = $this->graph->longLivedToken($short['access_token']);
        $token = $long['access_token'];

        $debug = $this->graph->debugToken($token);
        if (! $debug['is_valid']) {
            throw new MetaConnectionException('Meta did not accept the authorization. Please try connecting again.');
        }

        $missing = array_values(array_diff(MetaConfig::requiredPermissions(), $debug['scopes']));
        if ($missing !== []) {
            // Names only: these are our own configured permission names, safe to show.
            throw new MetaConnectionException('Some required Meta permissions were not granted: '.implode(', ', $missing).'. Reconnect and approve every permission.');
        }

        $me = $this->graph->me($token);
        if ($me['id'] === '') {
            throw new MetaConnectionException('Meta did not return an account identity. Please try again.');
        }
        $pages = $this->graph->pages($token);

        $connection = MetaConnection::query()->firstOrNew(['meta_user_id' => $me['id']]);
        $isNew = ! $connection->exists;

        // Pages owned by ANY connection (this workspace or another) are not offered again.
        $taken = Tenancy::withoutScope(fn () => MetaPage::query()->whereIn('page_id', array_column($pages, 'id'))->pluck('tenant_id', 'page_id'));
        $mine = $connection->exists ? $connection->pages()->get()->keyBy('page_id') : collect();

        $offer = [];
        foreach ($pages as $page) {
            if ($mine->has($page['id'])) {
                $mine[$page['id']]->forceFill(['access_token' => $page['access_token'], 'name' => $page['name']])->save(); // refreshed token
            } elseif (! $taken->has($page['id'])) {
                $offer[] = $page;
            }
        }

        $connection->forceFill([
            'created_by' => $connection->created_by ?? $user->getKey(),
            'meta_user_name' => $me['name'],
            'granted_permissions' => $debug['scopes'],
            'token_expires_at' => $debug['expires_at'] ? Carbon::createFromTimestamp($debug['expires_at']) : null,
            'data_access_expires_at' => $debug['data_access_expires_at'] ? Carbon::createFromTimestamp($debug['data_access_expires_at']) : null,
            'last_validated_at' => now(),
            'last_error_code' => null,
            'last_error_summary' => null,
            'disconnected_at' => null,
            'pending_assets' => $offer === [] ? null : $offer,
            'pending_assets_expires_at' => $offer === [] ? null : now()->addMinutes((int) config('meta.pending_assets_ttl_minutes', 30)),
            'status' => $mine->isNotEmpty() ? MetaConnectionStatus::Active : MetaConnectionStatus::PendingSelection,
        ])->save();

        AuditLogger::record($isNew ? 'meta.connection.created' : 'meta.connection.reauthorized', $connection, ['pages_offered' => count($offer)]);
        MetaLog::info('connection.authorized', ['connection' => $connection->public_id, 'pages_offered' => count($offer)]);

        return $connection;
    }
}
