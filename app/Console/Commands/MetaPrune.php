<?php

namespace App\Console\Commands;

use App\Enums\MetaEventStatus;
use App\Models\MetaLeadEvent;
use App\Services\Meta\MetaLog;
use App\Services\Meta\OAuthStateService;
use App\Support\Facades\Tenancy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('meta:prune')]
#[Description('Delete settled Meta event history and expired OAuth state past the retention window (META_RETENTION_DAYS).')]
class MetaPrune extends Command
{
    public function handle(OAuthStateService $states): int
    {
        $cutoff = now()->subDays((int) config('meta.retention_days', 90));

        // Never touch work in flight; everything else (incl. quarantined, which has no tenant) ages out.
        $events = Tenancy::withoutScope(fn () => MetaLeadEvent::query()
            ->whereNotIn('status', [MetaEventStatus::Received->value, MetaEventStatus::Processing->value, MetaEventStatus::NeedsReview->value])
            ->where('received_at', '<', $cutoff)->delete());

        $stateCount = Tenancy::withoutScope(fn () => $states->prune());

        MetaLog::info('prune', ['events' => $events, 'oauth_states' => $stateCount]);
        $this->info("Pruned {$events} event(s) and {$stateCount} OAuth state(s).");

        return self::SUCCESS;
    }
}
