<?php

namespace App\Http\Controllers;

use App\Actions\Leads\ArchiveLead;
use App\Models\Lead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LeadArchiveController extends Controller
{
    public function archive(Request $request, Lead $lead, ArchiveLead $action): RedirectResponse
    {
        Gate::authorize('archive', $lead);
        $action->archive($request->user(), $lead);

        return redirect()->route('leads.index')->with('status', 'Lead archived.');
    }

    public function restore(Request $request, Lead $lead, ArchiveLead $action): RedirectResponse
    {
        Gate::authorize('restore', $lead);
        $action->restore($request->user(), $lead);

        return redirect()->route('leads.show', $lead)->with('status', 'Lead restored.');
    }
}
