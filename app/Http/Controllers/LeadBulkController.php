<?php

namespace App\Http\Controllers;

use App\Actions\Leads\ArchiveLead;
use App\Actions\Leads\AssignLead;
use App\Actions\Leads\ChangeLeadStage;
use App\Enums\Permission;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\LeadVisibility;
use App\Support\AuditLogger;
use App\Support\TenantRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bulk assign / change stage / archive for selected leads (leads.bulk_update). Each lead is resolved through
 * visibility and authorized on its own with the same policy as the single-lead action, then changed through the same
 * action class, so a bulk change can never do what the user could not do one lead at a time.
 */
class LeadBulkController extends Controller
{
    public function __invoke(Request $request, AssignLead $assign, ChangeLeadStage $changeStage, ArchiveLead $archive): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->checkPermissionTo(Permission::LeadsBulkUpdate->value), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.(int) config('crm.bulk_max_leads', 100)],
            'ids.*' => ['required', 'string', 'size:26', 'distinct'],
            'action' => ['required', Rule::in(['assign', 'stage', 'archive'])],
            // owner_id null with action=assign unassigns the leads.
            'owner_id' => ['nullable', 'integer', TenantRules::activeMember()],
            'pipeline_stage_id' => ['required_if:action,stage', 'nullable', 'integer', TenantRules::exists('pipeline_stages', 'id', activeOnly: true)],
        ]);

        /** @var Collection<int, Lead> $leads */
        $leads = LeadVisibility::scope(Lead::query(), $user, includeArchived: true)
            ->whereIn('leads.public_id', $data['ids'])->orderBy('leads.id')->get();

        $ability = ['assign' => 'assign', 'stage' => 'changeStage', 'archive' => 'archive'][$data['action']];
        $changed = 0;
        $skipped = count($data['ids']) - $leads->count(); // ids the user cannot see count as skipped

        foreach ($leads as $lead) {
            if (! Gate::allows($ability, $lead)) {
                $skipped++;

                continue;
            }

            try {
                $did = match ($data['action']) {
                    'assign' => $this->assign($assign, $user, $lead, isset($data['owner_id']) ? (int) $data['owner_id'] : null),
                    'stage' => $changeStage($user, $lead, (int) $data['pipeline_stage_id']),
                    'archive' => $this->archive($archive, $user, $lead),
                };
            } catch (ValidationException) {
                $skipped++;

                continue;
            }

            $did ? $changed++ : $skipped++;
        }

        AuditLogger::record('leads.bulk_'.$data['action'], null, [
            'changed' => $changed, 'skipped' => $skipped,
            'owner_id' => $data['owner_id'] ?? null, 'pipeline_stage_id' => $data['pipeline_stage_id'] ?? null,
        ]);

        $message = $changed === 1 ? '1 lead updated.' : "{$changed} leads updated.";
        if ($skipped > 0) {
            $message .= " {$skipped} skipped (already in that state, or not allowed).";
        }

        return back()->with('status', $message);
    }

    private function assign(AssignLead $assign, User $user, Lead $lead, ?int $ownerId): bool
    {
        if ($lead->owner_id === $ownerId) {
            return false;
        }
        $assign($user, $lead, $ownerId);

        return true;
    }

    private function archive(ArchiveLead $archive, User $user, Lead $lead): bool
    {
        $archive->archive($user, $lead);

        return true;
    }
}
