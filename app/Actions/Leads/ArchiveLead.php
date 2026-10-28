<?php

namespace App\Actions\Leads;

use App\Models\Lead;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Archive = hide from lists, pipeline, dashboard and tasks without destroying history. Reversible. */
class ArchiveLead
{
    public function archive(User $actor, Lead $lead): void
    {
        DB::transaction(function () use ($actor, $lead) {
            $locked = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());
            if ($locked->isArchived()) {
                return;
            }
            $locked->forceFill(['archived_at' => now(), 'archived_by' => $actor->getKey()])->save();
            AuditLogger::record('lead.archived', $locked);
            $lead->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function restore(User $actor, Lead $lead): void
    {
        DB::transaction(function () use ($lead) {
            $locked = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());
            if (! $locked->isArchived()) {
                return;
            }
            $locked->forceFill(['archived_at' => null, 'archived_by' => null])->save();
            AuditLogger::record('lead.restored', $locked);
            $lead->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
