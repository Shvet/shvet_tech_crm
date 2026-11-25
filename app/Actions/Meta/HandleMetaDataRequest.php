<?php

namespace App\Actions\Meta;

use App\Enums\MetaConnectionStatus;
use App\Models\MetaConnection;
use App\Models\MetaDataDeletionRequest;
use App\Models\Tenant;
use App\Services\Meta\MetaLog;
use App\Support\AuditLogger;
use App\Support\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Acts on Meta's Deauthorize and Data Deletion callbacks for one app-scoped Facebook user id.
 *
 * What we hold about that person: the Facebook connection they authorized (their id, display name, granted
 * permissions and the Page tokens it produced) in every workspace they connected. Both callbacks delete the tokens
 * and Pages (no Graph calls: the tokens are already revoked). A deletion request also erases their Facebook user id
 * and name. Leads imported from Lead Ads are the advertiser's customer records, not this person's data, so they stay;
 * people who submitted a lead form are directed to the advertiser (see /data-deletion).
 */
class HandleMetaDataRequest
{
    public function __construct(private DisconnectMeta $disconnect) {}

    public function __invoke(string $metaUserId, string $kind): MetaDataDeletionRequest
    {
        $hash = MetaDataDeletionRequest::hashUserId($metaUserId);

        $connections = Tenancy::withoutScope(fn () => MetaConnection::query()->where('meta_user_id', $metaUserId)->get());
        $tenants = Tenancy::withoutScope(fn () => Tenant::query()->whereIn('id', $connections->pluck('tenant_id')->unique())->get()->keyBy('id'));

        foreach ($connections as $connection) {
            $tenant = $tenants->get($connection->tenant_id);
            if ($tenant === null) {
                continue;
            }

            Tenancy::runAs($tenant, function () use ($connection, $kind, $hash): void {
                DB::transaction(function () use ($connection, $kind, $hash): void {
                    if ($connection->status !== MetaConnectionStatus::Disconnected) {
                        $this->disconnect->connection($connection, notifyMeta: false);
                    }

                    if ($kind === MetaDataDeletionRequest::KIND_DELETION) {
                        // The column is required and unique per tenant, so replace the id with a non-reversible marker.
                        $connection->forceFill([
                            'meta_user_id' => 'deleted-'.substr($hash, 0, 40),
                            'meta_user_name' => null,
                        ])->save();
                    }

                    AuditLogger::record("meta.connection.{$kind}_callback", $connection);
                });
            });
        }

        $request = MetaDataDeletionRequest::query()->create([
            'confirmation_code' => strtoupper(Str::random(16)),
            'meta_user_hash' => $hash,
            'kind' => $kind,
            'status' => MetaDataDeletionRequest::STATUS_COMPLETED,
            'connections_affected' => $connections->count(),
            'completed_at' => now(),
        ]);

        MetaLog::info("data_request.{$kind}", ['connections' => $connections->count(), 'code' => $request->confirmation_code]);

        return $request;
    }
}
