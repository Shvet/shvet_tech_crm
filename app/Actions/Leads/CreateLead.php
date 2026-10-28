<?php

namespace App\Actions\Leads;

use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Exceptions\DuplicateLeadException;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadStageHistory;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Leads\ActiveMemberGuard;
use App\Services\Leads\DuplicateFinder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Creates a lead in the current tenant.
 *
 * - Idempotent per (tenant, submission_token, creator): a double click / retried POST returns the lead
 *   created by the first submission instead of inserting a second one.
 * - Possible duplicates (same phone or e-mail) raise DuplicateLeadException unless $confirmDuplicate.
 * - Users without leads.assign always own what they create; others may pick an owner (or none).
 *
 * @param  array<string, mixed>  $data  Validated input (see StoreLeadRequest); phone must already be E.164.
 */
class CreateLead
{
    /** @return array{lead: Lead, created: bool} */
    public function __invoke(User $actor, array $data, bool $confirmDuplicate = false): array
    {
        $token = $data['submission_token'] ?? null;

        if ($token && ($existing = $this->existing($actor, $token))) {
            return ['lead' => $existing, 'created' => false];
        }

        // Defense in depth: the form request validates this too, but other callers (imports, integrations) will not.
        if ($actor->checkPermissionTo(Permission::LeadsAssign->value) && ($data['owner_id'] ?? null) !== null) {
            ActiveMemberGuard::ensure((int) $data['owner_id'], 'owner_id');
        }

        if (! $confirmDuplicate) {
            $matches = DuplicateFinder::find($data['phone_e164'] ?? null, $data['email'] ?? null);
            if ($matches->isNotEmpty()) {
                throw new DuplicateLeadException($matches);
            }
        }

        $owner = $actor->checkPermissionTo(Permission::LeadsAssign->value)
            ? ($data['owner_id'] ?? null)
            : $actor->getKey();

        try {
            $lead = $this->persist($data, $owner, $actor->getKey(), $token);
        } catch (QueryException $e) {
            // Two concurrent submissions with the same token: the unique index let exactly one win.
            if ($token && $this->isDuplicateKey($e) && ($existing = $this->existing($actor, $token))) {
                return ['lead' => $existing, 'created' => false];
            }

            throw $e;
        }

        return ['lead' => $lead, 'created' => true];
    }

    /**
     * Creates a lead on behalf of an integration (no human actor): created_by stays null and the note, if any,
     * is system-authored. The caller has already validated source/stage/owner against the tenant (see MetaLeadImporter).
     * Idempotent on (external_provider, external_id): a repeat returns the existing lead with created=false.
     *
     * @param  array<string, mixed>  $data  Needs external_provider and external_id.
     * @return array{lead: Lead, created: bool}
     *
     * @throws DuplicateLeadException when phone/e-mail match an existing lead and $confirmDuplicate is false
     */
    public function import(array $data, bool $confirmDuplicate = false): array
    {
        $provider = $data['external_provider'];
        $externalId = $data['external_id'];

        if ($existing = $this->existingExternal($provider, $externalId)) {
            return ['lead' => $existing, 'created' => false];
        }

        if (($data['owner_id'] ?? null) !== null) {
            ActiveMemberGuard::ensure((int) $data['owner_id'], 'owner_id');
        }

        if (! $confirmDuplicate) {
            $matches = DuplicateFinder::find($data['phone_e164'] ?? null, $data['email'] ?? null);
            if ($matches->isNotEmpty()) {
                throw new DuplicateLeadException($matches);
            }
        }

        try {
            $lead = $this->persist($data, $data['owner_id'] ?? null, null, null);
        } catch (QueryException $e) {
            if ($this->isDuplicateKey($e) && ($existing = $this->existingExternal($provider, $externalId))) {
                return ['lead' => $existing, 'created' => false];
            }

            throw $e;
        }

        return ['lead' => $lead, 'created' => true];
    }

    /** @param  array<string, mixed>  $data */
    private function persist(array $data, ?int $ownerId, ?int $actorId, ?string $token): Lead
    {
        return DB::transaction(function () use ($data, $ownerId, $actorId, $token) {
            $stage = isset($data['pipeline_stage_id'])
                ? PipelineStage::query()->where('is_active', true)->findOrFail($data['pipeline_stage_id'])
                : PipelineStage::query()->where('is_active', true)->orderBy('position')->firstOrFail();

            $lead = Lead::query()->create([
                'full_name' => $data['full_name'],
                'phone_e164' => $data['phone_e164'] ?? null,
                'email' => $data['email'] ?? null,
                'company_name' => $data['company_name'] ?? null,
                'priority' => $data['priority'] ?? 'medium',
                'estimated_value' => $data['estimated_value'] ?? null,
                'lead_source_id' => $data['lead_source_id'],
                'pipeline_stage_id' => $stage->getKey(),
                'owner_id' => $ownerId,
                'created_by' => $actorId,
                'submission_token' => $token,
                'external_provider' => $data['external_provider'] ?? null,
                'external_id' => $data['external_id'] ?? null,
                'stage_changed_at' => now(),
            ]);

            LeadStageHistory::query()->create([
                'lead_id' => $lead->getKey(),
                'from_stage_id' => null,
                'to_stage_id' => $stage->getKey(),
                'changed_by' => $actorId,
                'changed_at' => now(),
            ]);

            if (filled($data['notes'] ?? null)) {
                LeadActivity::query()->create([
                    'lead_id' => $lead->getKey(),
                    'user_id' => $actorId,
                    'type' => ActivityType::Note,
                    'body' => $data['notes'],
                    'occurred_at' => now(),
                ]);
            }

            return $lead;
        });
    }

    private function existingExternal(string $provider, string $externalId): ?Lead
    {
        return Lead::query()->where('external_provider', $provider)->where('external_id', $externalId)->first();
    }

    private function existing(User $actor, string $token): ?Lead
    {
        return Lead::query()->where('submission_token', $token)->where('created_by', $actor->getKey())->first();
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
