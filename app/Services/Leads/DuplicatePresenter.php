<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Collection;

/** Describes duplicate matches to the user WITHOUT leaking leads they are not allowed to see. */
class DuplicatePresenter
{
    /** @param  Collection<int, Lead>  $matches */
    public static function forUser(Collection $matches, User $user, ?string $phoneE164, ?string $email): array
    {
        return $matches->map(function (Lead $lead) use ($user, $phoneE164, $email) {
            $reason = array_values(array_filter([
                $phoneE164 !== null && $lead->phone_e164 === $phoneE164 ? 'phone' : null,
                $email !== null && $lead->email === $email ? 'email' : null,
            ]));

            if (! LeadVisibility::canView($user, $lead)) {
                return ['visible' => false, 'matches' => $reason];
            }

            return [
                'visible' => true,
                'id' => $lead->public_id,
                'full_name' => $lead->full_name,
                'matches' => $reason,
                'archived' => $lead->isArchived(),
            ];
        })->values()->all();
    }
}
