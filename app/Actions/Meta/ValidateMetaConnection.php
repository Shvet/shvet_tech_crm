<?php

namespace App\Actions\Meta;

use App\Enums\MetaConnectionStatus;
use App\Exceptions\MetaApiException;
use App\Models\MetaConnection;
use App\Services\Meta\GraphClient;
use App\Services\Meta\MetaConfig;
use App\Services\Meta\MetaLog;
use App\Support\AuditLogger;
use Illuminate\Support\Carbon;

/** Health check: are the stored Page tokens still valid and do they still carry the required permissions? */
class ValidateMetaConnection
{
    public function __construct(private GraphClient $graph) {}

    public function __invoke(MetaConnection $connection): MetaConnection
    {
        $page = $connection->pages()->orderBy('id')->first();
        if (! $page || $connection->status === MetaConnectionStatus::Disconnected) {
            return $connection;
        }

        try {
            $debug = $this->graph->debugToken($page->access_token);
        } catch (MetaApiException $e) {
            if ($e->isRetryable()) {
                MetaLog::warning('validate.transient', ['connection' => $connection->public_id, 'code' => $e->code()]);

                return $connection; // try again at the next scheduled run; don't flap the status
            }
            $this->flag($connection, $e->code(), $e->adminMessage());

            return $connection;
        }

        $missing = array_values(array_diff(MetaConfig::requiredPermissions(), $debug['scopes']));
        if (! $debug['is_valid']) {
            $this->flag($connection, 'meta_token_invalid', 'Meta no longer accepts the saved authorization. Reconnect the integration.');
        } elseif ($missing !== []) {
            $this->flag($connection, 'meta_permissions_missing', 'Required Meta permissions are missing: '.implode(', ', $missing).'. Reconnect and approve them.');
        } else {
            $connection->forceFill([
                'status' => MetaConnectionStatus::Active,
                'granted_permissions' => $debug['scopes'],
                'data_access_expires_at' => $debug['data_access_expires_at'] ? Carbon::createFromTimestamp($debug['data_access_expires_at']) : null,
                'last_validated_at' => now(),
                'last_error_code' => null,
                'last_error_summary' => null,
            ])->save();
        }

        return $connection;
    }

    private function flag(MetaConnection $connection, string $code, string $summary): void
    {
        $changed = $connection->status !== MetaConnectionStatus::ReauthorizationRequired;
        $connection->forceFill([
            'status' => MetaConnectionStatus::ReauthorizationRequired,
            'last_validated_at' => now(),
            'last_error_code' => $code,
            'last_error_summary' => $summary,
        ])->save();

        if ($changed) {
            AuditLogger::record('meta.connection.reauthorization_required', $connection, ['code' => $code]);
        }
    }
}
