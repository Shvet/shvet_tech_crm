<?php

namespace App\Actions\Meta;

use App\Enums\MetaConnectionStatus;
use App\Exceptions\MetaApiException;
use App\Exceptions\MetaConnectionException;
use App\Models\MetaConnection;
use App\Models\MetaPage;
use App\Services\Meta\GraphClient;
use App\Services\Meta\MetaLog;
use App\Support\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Connects the chosen Pages. Page ids/tokens come ONLY from the server-side pending blob created at OAuth time;
 * the browser merely says which of those to keep, so it can never inject a Page or a token.
 */
class ConnectMetaPages
{
    public function __construct(private GraphClient $graph, private SyncMetaForms $syncForms) {}

    /**
     * @param  list<string>  $pageIds
     * @return list<MetaPage>
     */
    public function __invoke(MetaConnection $connection, array $pageIds): array
    {
        $blob = $connection->pending_assets;
        $expired = ! $connection->pending_assets_expires_at || $connection->pending_assets_expires_at->isPast();

        if (! is_array($blob) || $blob === [] || $expired) {
            throw new MetaConnectionException('The list of Pages has expired. Reconnect to Meta to load it again.');
        }

        $offered = collect($blob)->keyBy('id');
        $chosen = collect($pageIds)->unique()->map(fn ($id) => $offered->get((string) $id))->filter();

        if ($chosen->isEmpty()) {
            throw new MetaConnectionException('Choose at least one Page from the list.');
        }

        $connected = [];
        foreach ($chosen as $asset) {
            try {
                $page = DB::transaction(fn () => MetaPage::query()->create([
                    'meta_connection_id' => $connection->getKey(),
                    'page_id' => $asset['id'],
                    'name' => $asset['name'],
                    'access_token' => $asset['access_token'],
                    'subscription_status' => 'pending',
                ]));
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    continue; // somebody connected this Page first (globally unique); skip silently
                }
                throw $e;
            }

            $this->subscribe($page);
            try {
                $this->syncForms->__invoke($page);
            } catch (MetaApiException $e) {
                MetaLog::warning('pages.forms_sync_failed', ['page' => $page->public_id, 'code' => $e->code()]);
            }

            AuditLogger::record('meta.page.connected', $page, ['subscription' => $page->subscription_status]);
            $connected[] = $page;
        }

        $remaining = $offered->reject(fn ($a) => $chosen->contains('id', $a['id']))->values()->all();
        $connection->forceFill([
            'status' => MetaConnectionStatus::Active,
            'pending_assets' => $remaining === [] ? null : $remaining,
            'pending_assets_expires_at' => $remaining === [] ? null : $connection->pending_assets_expires_at,
        ])->save();

        return $connected;
    }

    public function subscribe(MetaPage $page): void
    {
        try {
            $this->graph->subscribePage($page->page_id, $page->access_token);
            $page->forceFill(['subscription_status' => 'subscribed', 'subscription_error' => null, 'subscribed_at' => now()])->save();
        } catch (MetaApiException $e) {
            $page->forceFill(['subscription_status' => 'failed', 'subscription_error' => $e->adminMessage()])->save();
            MetaLog::warning('pages.subscribe_failed', ['page' => $page->public_id, 'code' => $e->code()]);
        }
    }
}
