<?php

namespace App\Console\Commands;

use App\Enums\MetaEventStatus;
use App\Jobs\Meta\ProcessMetaLeadEvent;
use App\Models\MetaLeadEvent;
use App\Services\Meta\MetaLog;
use App\Support\Facades\Tenancy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('meta:recover-events {--limit=200 : Maximum events to re-dispatch per run}')]
#[Description('Re-dispatch Meta lead events whose queue job was lost (dispatch failure, crashed worker, lost job). Safe to run repeatedly.')]
class MetaRecoverEvents extends Command
{
    public function handle(): int
    {
        $stale = (int) config('meta.recovery_stale_minutes', 5);
        $stuck = (int) config('meta.processing_stuck_minutes', 15);
        // An event that already had an attempt may legitimately be waiting out a retry delay; give it the full delay first.
        $retryWindow = (int) ceil((int) config('meta.job.max_release_seconds', 3600) / 60) + $stale;
        $budget = (int) $this->option('limit');
        $dispatched = 0;

        Tenancy::eachActiveTenant(function () use ($stale, $stuck, $retryWindow, &$budget, &$dispatched): void {
            // Crashed mid-import: hand back first.
            MetaLeadEvent::query()->where('status', MetaEventStatus::Processing->value)
                ->where('last_attempt_at', '<', now()->subMinutes($stuck))
                ->update(['status' => MetaEventStatus::Received->value]);

            $events = MetaLeadEvent::query()->where('status', MetaEventStatus::Received->value)
                ->whereRaw('COALESCE(last_attempt_at, received_at) < ?', [now()->subMinutes($stale)])
                ->orderBy('id')->limit(max($budget, 0))->get();

            foreach ($events as $event) {
                $waiting = $event->attempts > 0 && ($event->last_attempt_at ?? $event->received_at)->gt(now()->subMinutes($retryWindow));
                if ($waiting) {
                    continue;
                }
                try {
                    ProcessMetaLeadEvent::dispatch($event->public_id, (int) $event->tenant_id);
                    $dispatched++;
                    $budget--;
                } catch (Throwable $e) {
                    MetaLog::error('recover.dispatch_failed', ['event' => $event->public_id, 'exception' => $e::class]);
                }
            }
        });

        $this->info("Re-dispatched {$dispatched} event(s).");

        return self::SUCCESS;
    }
}
