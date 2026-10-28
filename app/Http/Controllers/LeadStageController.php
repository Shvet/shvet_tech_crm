<?php

namespace App\Http\Controllers;

use App\Actions\Leads\ChangeLeadStage;
use App\Exceptions\StageConflictException;
use App\Http\Requests\ChangeLeadStageRequest;
use App\Models\Lead;
use Illuminate\Http\RedirectResponse;

class LeadStageController extends Controller
{
    public function update(ChangeLeadStageRequest $request, Lead $lead, ChangeLeadStage $changeStage): RedirectResponse
    {
        try {
            $changed = $changeStage(
                $request->user(),
                $lead,
                (int) $request->validated('pipeline_stage_id'),
                $request->filled('expected_stage_id') ? (int) $request->input('expected_stage_id') : null,
            );
        } catch (StageConflictException $e) {
            return back()->withErrors(['stage' => $e->getMessage()]);
        }

        return back()->with('status', $changed ? 'Stage updated.' : 'Lead is already in that stage.');
    }
}
