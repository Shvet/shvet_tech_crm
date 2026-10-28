<?php

namespace App\Console\Commands;

use App\Actions\Meta\ValidateMetaConnection;
use App\Enums\MetaConnectionStatus;
use App\Models\MetaConnection;
use App\Services\Meta\MetaConfig;
use App\Support\Facades\Tenancy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('meta:validate-connections')]
#[Description('Check every active Meta connection (token validity and permissions); flags those that need reauthorization.')]
class MetaValidateConnections extends Command
{
    public function handle(ValidateMetaConnection $validate): int
    {
        if (! MetaConfig::isConfigured()) {
            $this->warn('Meta is not configured; nothing to validate.');

            return self::SUCCESS;
        }

        $checked = 0;
        Tenancy::eachActiveTenant(function () use ($validate, &$checked): void {
            $connections = MetaConnection::query()
                ->whereIn('status', [MetaConnectionStatus::Active->value, MetaConnectionStatus::ReauthorizationRequired->value])->get();

            foreach ($connections as $connection) {
                $validate($connection);
                $checked++;
            }
        });

        $this->info("Validated {$checked} connection(s).");

        return self::SUCCESS;
    }
}
