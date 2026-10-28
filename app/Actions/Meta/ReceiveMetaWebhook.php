<?php

namespace App\Actions\Meta;

use App\Enums\MetaEventStatus;
use App\Jobs\Meta\ProcessMetaLeadEvent;
use App\Models\MetaLeadEvent;
use App\Models\MetaPage;
use App\Services\Meta\MetaLog;
use App\Support\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Persists the `leadgen` changes of a signature-verified webhook payload, then enqueues one job per NEW event.
 *
 * - The tenant is derived ONLY from our own meta_pages row for the Page id (globally unique). Nothing in the payload
 *   names a tenant. Unknown Pages are stored as "quarantined" with no tenant and never dispatched.
 * - Persist first, dispatch second: if dispatch fails the row stays "received" and `meta:recover-events` picks it up.
 * - Redeliveries collapse on UNIQUE(page_id, leadgen_id).
 */
class ReceiveMetaWebhook
{
    /** @param  array<string, mixed>  $payload  Decoded, signature-verified JSON. @return array{received: int, duplicates: int, quarantined: int} */
    public function __invoke(array $payload): array
    {
        $stats = ['received' => 0, 'duplicates' => 0, 'quarantined' => 0];
        $limit = (int) config('meta.webhook_max_changes', 100);
        $seen = 0;

        foreach ($this->changes($payload) as $change) {
            if (++$seen > $limit) {
                MetaLog::warning('webhook.truncated', ['limit' => $limit]);
                break;
            }

            $created = $this->store($change);
            if ($created === null) {
                $stats['duplicates']++;

                continue;
            }

            $stats[$created->status === MetaEventStatus::Quarantined ? 'quarantined' : 'received']++;

            if ($created->status === MetaEventStatus::Received) {
                $this->dispatch($created);
            }
        }

        return $stats;
    }

    /** @return iterable<array<string, mixed>> */
    private function changes(array $payload): iterable
    {
        if (($payload['object'] ?? null) !== 'page') {
            return;
        }

        foreach (is_array($payload['entry'] ?? null) ? $payload['entry'] : [] as $entry) {
            foreach (is_array($entry['changes'] ?? null) ? $entry['changes'] : [] as $change) {
                $value = $change['value'] ?? null;
                if (($change['field'] ?? null) !== 'leadgen' || ! is_array($value)) {
                    continue;
                }
                $pageId = $this->id($value['page_id'] ?? ($entry['id'] ?? null));
                $leadgenId = $this->id($value['leadgen_id'] ?? null);
                if ($pageId === null || $leadgenId === null) {
                    continue; // malformed change: nothing identifiable to store
                }

                yield [
                    'page_id' => $pageId,
                    'leadgen_id' => $leadgenId,
                    'form_id' => $this->id($value['form_id'] ?? null),
                    'ad_id' => $this->id($value['ad_id'] ?? null),
                    'adgroup_id' => $this->id($value['adgroup_id'] ?? null),
                    'created_time' => isset($value['created_time']) && is_numeric($value['created_time'])
                        ? Carbon::createFromTimestampUTC((int) $value['created_time']) : null,
                ];
            }
        }
    }

    /** Meta ids are numeric strings; reject anything else so odd input never reaches a column or a URL path. */
    private function id(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = (string) $value;

        return preg_match('/^\d{1,40}$/', $value) === 1 ? $value : null;
    }

    /** @param  array<string, mixed>  $change */
    private function store(array $change): ?MetaLeadEvent
    {
        $page = Tenancy::withoutScope(fn () => MetaPage::query()->where('page_id', $change['page_id'])->first());

        try {
            return Tenancy::withoutScope(fn () => MetaLeadEvent::query()->create($change + [
                'tenant_id' => $page?->tenant_id,
                'meta_page_id' => $page?->getKey(),
                'status' => $page ? MetaEventStatus::Received : MetaEventStatus::Quarantined,
                'outcome' => $page ? null : 'unknown_page',
                'correlation_id' => (string) Str::ulid(),
                'received_at' => now(),
            ]));
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return null; // redelivery of an event we already hold
            }

            throw $e;
        }
    }

    private function dispatch(MetaLeadEvent $event): void
    {
        try {
            ProcessMetaLeadEvent::dispatch($event->public_id, (int) $event->tenant_id);
        } catch (Throwable $e) {
            // The event is already durable; the recovery command re-dispatches it.
            MetaLog::error('webhook.dispatch_failed', ['event' => $event->public_id, 'exception' => $e::class]);
        }
    }
}
