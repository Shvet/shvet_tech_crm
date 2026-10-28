<?php

namespace App\Actions\Leads;

use App\Enums\ActivityType;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LogActivity
{
    public function __invoke(User $actor, Lead $lead, ActivityType $type, ?string $subject, ?string $body, CarbonInterface $occurredAt): LeadActivity
    {
        return DB::transaction(function () use ($actor, $lead, $type, $subject, $body, $occurredAt) {
            $locked = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());

            if ($locked->isArchived()) {
                throw ValidationException::withMessages(['lead' => 'Archived leads cannot receive new activity. Restore the lead first.']);
            }

            $activity = LeadActivity::query()->create([
                'lead_id' => $locked->getKey(),
                'user_id' => $actor->getKey(),
                'type' => $type,
                'subject' => $subject,
                'body' => $body,
                'occurred_at' => $occurredAt,
            ]);

            // Only real contact moves last_contacted_at forward (never backwards for a back-dated entry).
            if ($type->countsAsContact() && ($locked->last_contacted_at === null || $occurredAt->greaterThan($locked->last_contacted_at))) {
                $locked->forceFill(['last_contacted_at' => $occurredAt])->save();
            }

            return $activity;
        });
    }
}
