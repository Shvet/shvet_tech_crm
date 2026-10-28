<?php

namespace App\Actions\Meta;

use App\Enums\MetaConnectionStatus;
use App\Exceptions\MetaApiException;
use App\Models\MetaConnection;
use App\Models\MetaPage;
use App\Services\Meta\GraphClient;
use App\Services\Meta\MetaLog;
use App\Support\AuditLogger;

/**
 * Safe disconnection: best-effort unsubscribe at Meta, then ALWAYS delete our copy of the Page token and mappings
 * (so a Meta outage cannot leave live credentials behind). Imported leads and event history are kept.
 */
class DisconnectMeta
{
    public function __construct(private GraphClient $graph) {}

    public function page(MetaPage $page): void
    {
        $this->unsubscribe($page);
        AuditLogger::record('meta.page.disconnected', $page);
        $page->delete(); // mappings cascade; the Page id becomes available to be connected again
    }

    public function connection(MetaConnection $connection): void
    {
        foreach ($connection->pages()->get() as $page) {
            $this->unsubscribe($page);
            $page->delete();
        }

        $connection->forceFill([
            'status' => MetaConnectionStatus::Disconnected,
            'pending_assets' => null,
            'pending_assets_expires_at' => null,
            'granted_permissions' => null,
            'disconnected_at' => now(),
            'last_error_code' => null,
            'last_error_summary' => null,
        ])->save();

        AuditLogger::record('meta.connection.disconnected', $connection);
        MetaLog::info('connection.disconnected', ['connection' => $connection->public_id]);
    }

    private function unsubscribe(MetaPage $page): void
    {
        try {
            $this->graph->unsubscribePage($page->page_id, $page->access_token);
        } catch (MetaApiException $e) {
            MetaLog::warning('disconnect.unsubscribe_failed', ['page' => $page->public_id, 'code' => $e->code()]);
        }
    }
}
