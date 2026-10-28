<?php

namespace App\Actions\Meta;

use App\Actions\Leads\CreateLead;
use App\Enums\MetaConnectionStatus;
use App\Enums\MetaEventStatus;
use App\Exceptions\DuplicateLeadException;
use App\Exceptions\MetaApiException;
use App\Models\MetaFormMapping;
use App\Models\MetaLeadEvent;
use App\Models\MetaPage;
use App\Services\Meta\GraphClient;
use App\Services\Meta\MetaLeadMapper;
use App\Services\Meta\MetaLog;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Imports ONE received webhook event into the current tenant. Must run inside a tenant context.
 *
 * Outcomes (stored on the event, never silent):
 *  imported      lead created (outcome "created") or already existed for this Meta lead id ("already_imported")
 *  needs_review  possible duplicate of an existing lead: NOTHING is created or overwritten; an admin decides
 *  ignored       no enabled mapping for the form (replayable once mapped)
 *  failed        non-retryable problem (auth/permission/invalid data); replayable after the cause is fixed
 * Retryable Graph errors are re-thrown (the event is released back to "received") so the job can back off.
 */
class ImportMetaLead
{
    public function __construct(private GraphClient $graph, private MetaLeadMapper $mapper, private CreateLead $createLead) {}

    /** @throws MetaApiException when the failure is retryable */
    public function __invoke(MetaLeadEvent $event): void
    {
        if (! $this->claim($event)) {
            return; // another worker has it, or it is already settled
        }

        $event->refresh();
        $ctx = ['event' => $event->public_id, 'correlation_id' => $event->correlation_id, 'page_id' => $event->page_id];

        try {
            $page = $this->activePage($event);
            if (! $page) {
                $this->settle($event, MetaEventStatus::Failed, failure: ['connection_inactive', 'The Meta connection or Page is no longer active. Reconnect, then retry.']);
                MetaLog::warning('import.connection_inactive', $ctx);

                return;
            }

            $mapping = $event->form_id ? $this->mappingFor($page, $event->form_id) : null;
            if ($event->form_id && ! $mapping?->is_enabled) {
                $this->settle($event, MetaEventStatus::Ignored, outcome: 'no_mapping');
                MetaLog::info('import.ignored_no_mapping', $ctx);

                return;
            }

            $payload = $this->graph->lead($event->leadgen_id, $page->access_token);

            if ((string) ($payload['id'] ?? '') !== $event->leadgen_id) {
                $this->settle($event, MetaEventStatus::Failed, failure: ['lead_mismatch', 'Meta returned a different lead than requested.']);

                return;
            }

            if (! $mapping) {
                $formId = (string) ($payload['form_id'] ?? '');
                $mapping = $formId !== '' ? $this->mappingFor($page, $formId) : null;
                if (! $mapping?->is_enabled) {
                    $this->settle($event, MetaEventStatus::Ignored, outcome: 'no_mapping');

                    return;
                }
            }

            ['data' => $data, 'warnings' => $warnings] = $this->mapper->map($payload, $mapping);
            $event->forceFill([
                'platform' => $payload['platform'] ?? $event->platform,
                'ad_id' => $payload['ad_id'] ?? $event->ad_id,
                'campaign_id' => $payload['campaign_id'] ?? $event->campaign_id,
                'is_organic' => $payload['is_organic'] ?? $event->is_organic,
            ]);

            try {
                ['lead' => $lead, 'created' => $created] = $this->createLead->import($data, $event->confirm_duplicate);
            } catch (DuplicateLeadException $e) {
                $event->forceFill(['matched_lead_id' => $e->matches->first()?->getKey(), 'warnings' => $warnings]);
                $this->settle($event, MetaEventStatus::NeedsReview, outcome: 'possible_duplicate');
                AuditLogger::record('meta.lead.needs_review', $event, ['matches' => $e->matches->count()]);
                MetaLog::info('import.needs_review', $ctx);

                return;
            } catch (ValidationException) {
                // e.g. the default owner left the workspace after the mapping was saved.
                $this->settle($event, MetaEventStatus::Failed, failure: ['mapping_invalid', 'The form mapping points to an unavailable owner, source or stage. Fix the mapping, then retry.']);
                MetaLog::warning('import.mapping_invalid', $ctx);

                return;
            }

            $event->forceFill(['lead_id' => $lead->getKey(), 'warnings' => $warnings]);
            $this->settle($event, MetaEventStatus::Imported, outcome: $created ? 'created' : 'already_imported');

            if ($created) {
                AuditLogger::record('meta.lead.imported', $lead, ['event' => $event->public_id, 'warnings' => $warnings]);
            }
            $mapping->forceFill(['last_import_at' => now()])->save();
            $page->forceFill(['last_sync_at' => now()])->save();
            $page->connection()->update(['last_sync_at' => now()]);
            MetaLog::info('import.done', $ctx + ['outcome' => $created ? 'created' : 'already_imported']);
        } catch (MetaApiException $e) {
            $this->handleApiError($event, $page ?? null, $e, $ctx);
        }
    }

    /** @param  array<string, mixed>  $ctx */
    private function handleApiError(MetaLeadEvent $event, ?MetaPage $page, MetaApiException $e, array $ctx): void
    {
        MetaLog::warning('import.api_error', $ctx + ['code' => $e->code(), 'category' => $e->category]);

        if ($e->isRetryable()) {
            // Back to "received" so the retry can claim it again.
            $event->forceFill(['status' => MetaEventStatus::Received, 'failure_code' => $e->code(), 'failure_summary' => $e->adminMessage()])->save();
            throw $e;
        }

        if ($e->requiresReauthorization() && $page) {
            $page->connection()->update([
                'status' => MetaConnectionStatus::ReauthorizationRequired->value,
                'last_error_code' => $e->code(),
                'last_error_summary' => $e->adminMessage(),
            ]);
            AuditLogger::record('meta.connection.reauthorization_required', $page->connection, ['code' => $e->code()]);
        }

        $this->settle($event, MetaEventStatus::Failed, failure: [$e->code(), $e->adminMessage()]);
    }

    private function claim(MetaLeadEvent $event): bool
    {
        return MetaLeadEvent::query()->whereKey($event->getKey())
            ->where('status', MetaEventStatus::Received->value)
            ->update([
                'status' => MetaEventStatus::Processing->value,
                'attempts' => DB::raw('attempts + 1'),
                'last_attempt_at' => now(),
            ]) === 1;
    }

    private function activePage(MetaLeadEvent $event): ?MetaPage
    {
        if (! $event->meta_page_id) {
            return null;
        }

        $page = MetaPage::query()->with('connection')->find($event->meta_page_id);

        return $page
            && $page->page_id === $event->page_id
            && $page->subscription_status !== 'unsubscribed'
            && $page->connection?->status === MetaConnectionStatus::Active
            ? $page : null;
    }

    private function mappingFor(MetaPage $page, string $formId): ?MetaFormMapping
    {
        return MetaFormMapping::query()->where('meta_page_id', $page->getKey())->where('form_id', $formId)->first();
    }

    /** @param  array{0: string, 1: string}|null  $failure  [code, admin-safe summary] */
    private function settle(MetaLeadEvent $event, MetaEventStatus $status, ?string $outcome = null, ?array $failure = null): void
    {
        $event->forceFill([
            'status' => $status,
            'outcome' => $outcome,
            'failure_code' => $failure[0] ?? null,
            'failure_summary' => $failure[1] ?? null,
            'processed_at' => $status === MetaEventStatus::Failed ? null : now(),
        ])->save();
    }
}
