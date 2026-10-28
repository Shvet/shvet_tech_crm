<?php

namespace App\Services\Leads;

use App\Models\Lead;
use Illuminate\Support\Collection;

/** Finds other leads in the CURRENT tenant sharing a phone number or e-mail. Never merges or blocks silently. */
class DuplicateFinder
{
    /** @return Collection<int, Lead> */
    public static function find(?string $phoneE164, ?string $email, ?int $exceptLeadId = null): Collection
    {
        if ($phoneE164 === null && $email === null) {
            return collect();
        }

        return Lead::query()
            ->where(function ($q) use ($phoneE164, $email) {
                if ($phoneE164 !== null) {
                    $q->orWhere('phone_e164', $phoneE164);
                }
                if ($email !== null) {
                    $q->orWhere('email', $email);
                }
            })
            ->when($exceptLeadId, fn ($q) => $q->where('id', '!=', $exceptLeadId))
            ->orderBy('id')
            ->limit(5)
            ->get(['id', 'public_id', 'full_name', 'phone_e164', 'email', 'owner_id', 'archived_at']);
    }
}
