<?php

namespace App\Actions\Meta;

use App\Enums\MetaEventStatus;
use App\Exceptions\MetaConnectionException;
use App\Jobs\Meta\ProcessMetaLeadEvent;
use App\Models\MetaLeadEvent;
use App\Services\Meta\MetaLog;
use App\Support\AuditLogger;
use Throwable;

/** Admin recovery actions on a stored event. Each is a guarded status transition (no-op if the state changed meanwhile). */
class ReplayMetaEvent
{
    public function retry(MetaLeadEvent $event): void
    {
        $this->transition($event, [MetaEventStatus::Failed, MetaEventStatus::Ignored], MetaEventStatus::Received, 'meta.event.replayed', ['attempts' => 0]);
    }

    /** "Create anyway": the admin looked at the flagged duplicate and wants a new lead regardless. */
    public function createAnyway(MetaLeadEvent $event): void
    {
        $this->transition($event, [MetaEventStatus::NeedsReview], MetaEventStatus::Received, 'meta.event.duplicate_confirmed', ['confirm_duplicate' => true, 'attempts' => 0]);
    }

    public function dismiss(MetaLeadEvent $event): void
    {
        $changed = MetaLeadEvent::query()->whereKey($event->getKey())->where('status', MetaEventStatus::NeedsReview->value)
            ->update(['status' => MetaEventStatus::Dismissed->value, 'processed_at' => now()]);

        if ($changed !== 1) {
            throw new MetaConnectionException('This item is no longer waiting for review.');
        }
        AuditLogger::record('meta.event.dismissed', $event);
    }

    /** @param  list<MetaEventStatus>  $from */
    private function transition(MetaLeadEvent $event, array $from, MetaEventStatus $to, string $audit, array $extra): void
    {
        $changed = MetaLeadEvent::query()->whereKey($event->getKey())->whereIn('status', array_map(fn ($s) => $s->value, $from))
            ->update(['status' => $to->value, 'failure_code' => null, 'failure_summary' => null, 'outcome' => null, 'processed_at' => null] + $extra);

        if ($changed !== 1) {
            throw new MetaConnectionException('This item can no longer be retried.');
        }

        AuditLogger::record($audit, $event);

        try {
            ProcessMetaLeadEvent::dispatch($event->public_id, (int) $event->tenant_id);
        } catch (Throwable $e) {
            MetaLog::error('replay.dispatch_failed', ['event' => $event->public_id, 'exception' => $e::class]); // recovery command will pick it up
        }
    }
}
