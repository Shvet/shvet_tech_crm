<?php

namespace App\Actions\Leads;

use App\Exceptions\StageConflictException;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a lead to another stage of the SAME tenant and records who did it and when.
 * Runs under a row lock so concurrent moves serialize and the history's "from" is always truthful.
 */
class ChangeLeadStage
{
    /** @return bool true if the stage changed, false if the lead was already there (no history row written) */
    public function __invoke(User $actor, Lead $lead, int $toStageId, ?int $expectedFromStageId = null): bool
    {
        return DB::transaction(function () use ($actor, $lead, $toStageId, $expectedFromStageId) {
            $locked = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());

            if ($locked->isArchived()) {
                throw ValidationException::withMessages(['lead' => 'Archived leads cannot be moved. Restore the lead first.']);
            }
            if ($expectedFromStageId !== null && (int) $locked->pipeline_stage_id !== $expectedFromStageId) {
                throw new StageConflictException;
            }

            // Tenant-scoped lookup: a stage id from another tenant simply does not exist here.
            $target = PipelineStage::query()->where('is_active', true)->find($toStageId)
                ?? throw ValidationException::withMessages(['pipeline_stage_id' => 'Choose a valid pipeline stage.']);

            if ((int) $locked->pipeline_stage_id === $target->getKey()) {
                return false;
            }

            $from = $locked->pipeline_stage_id;
            $locked->forceFill(['pipeline_stage_id' => $target->getKey(), 'stage_changed_at' => now()])->save();

            LeadStageHistory::query()->create([
                'lead_id' => $locked->getKey(),
                'from_stage_id' => $from,
                'to_stage_id' => $target->getKey(),
                'changed_by' => $actor->getKey(),
                'changed_at' => now(),
            ]);

            $lead->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }
}
