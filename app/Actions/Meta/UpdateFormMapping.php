<?php

namespace App\Actions\Meta;

use App\Models\LeadSource;
use App\Models\MetaFormMapping;
use App\Models\PipelineStage;
use App\Services\Leads\ActiveMemberGuard;
use App\Support\AuditLogger;
use Illuminate\Validation\ValidationException;

/**
 * Saves a form mapping. The form request validates tenant-scoped references; this action re-checks them (defence in
 * depth: also reachable from non-HTTP callers) and the database enforces them with composite foreign keys.
 */
class UpdateFormMapping
{
    /** @param  array<string, mixed>  $data */
    public function __invoke(MetaFormMapping $mapping, array $data): MetaFormMapping
    {
        $sourceId = $data['lead_source_id'] ?? null;
        $stageId = $data['pipeline_stage_id'] ?? null;
        $ownerId = $data['default_owner_id'] ?? null;
        $enabled = (bool) ($data['is_enabled'] ?? false);

        if ($sourceId !== null && ! LeadSource::query()->where('is_active', true)->whereKey($sourceId)->exists()) {
            throw ValidationException::withMessages(['lead_source_id' => 'Choose a lead source from this workspace.']);
        }
        if ($stageId !== null && ! PipelineStage::query()->where('is_active', true)->whereKey($stageId)->exists()) {
            throw ValidationException::withMessages(['pipeline_stage_id' => 'Choose a pipeline stage from this workspace.']);
        }
        if ($ownerId !== null) {
            ActiveMemberGuard::ensure((int) $ownerId, 'default_owner_id');
        }
        if ($enabled && ($sourceId === null || $stageId === null)) {
            throw ValidationException::withMessages(['is_enabled' => 'Choose a lead source and a starting stage before enabling this form.']);
        }

        $mapping->forceFill([
            'lead_source_id' => $sourceId,
            'pipeline_stage_id' => $stageId,
            'default_owner_id' => $ownerId,
            'is_enabled' => $enabled,
            'field_map' => $this->fieldMap($data['field_map'] ?? null),
        ])->save();

        AuditLogger::record('meta.mapping.updated', $mapping, ['enabled' => $enabled]);

        return $mapping;
    }

    /** @return array<string, string>|null */
    private function fieldMap(mixed $map): ?array
    {
        if (! is_array($map)) {
            return null;
        }
        $clean = [];
        foreach (['full_name', 'first_name', 'last_name', 'email', 'phone', 'company_name'] as $target) {
            if (isset($map[$target]) && is_string($map[$target]) && trim($map[$target]) !== '') {
                $clean[$target] = mb_substr(trim($map[$target]), 0, 100);
            }
        }

        return $clean === [] ? null : $clean;
    }
}
