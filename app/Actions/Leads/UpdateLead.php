<?php

namespace App\Actions\Leads;

use App\Exceptions\DuplicateLeadException;
use App\Models\Lead;
use App\Services\Leads\DuplicateFinder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits contact/commercial details. Owner and stage are deliberately NOT editable here:
 * they have their own authorized actions (AssignLead, ChangeLeadStage).
 */
class UpdateLead
{
    /** @param  array<string, mixed>  $data */
    public function __invoke(Lead $lead, array $data, bool $confirmDuplicate = false): Lead
    {
        $phoneChanged = array_key_exists('phone_e164', $data) && $data['phone_e164'] !== $lead->phone_e164;
        $emailChanged = array_key_exists('email', $data) && $data['email'] !== $lead->email;

        if (($phoneChanged || $emailChanged) && ! $confirmDuplicate) {
            $matches = DuplicateFinder::find(
                $phoneChanged ? $data['phone_e164'] : null,
                $emailChanged ? $data['email'] : null,
                $lead->getKey(),
            );
            if ($matches->isNotEmpty()) {
                throw new DuplicateLeadException($matches);
            }
        }

        return DB::transaction(function () use ($lead, $data) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());

            if ($lead->isArchived()) {
                throw ValidationException::withMessages(['lead' => 'Archived leads cannot be edited. Restore the lead first.']);
            }

            $lead->fill(array_intersect_key($data, array_flip([
                'full_name', 'phone_e164', 'email', 'company_name', 'priority', 'estimated_value', 'lead_source_id',
            ])))->save();

            return $lead;
        });
    }
}
