<?php

namespace App\Http\Controllers;

use App\Actions\Leads\AssignLead;
use App\Http\Requests\AssignLeadRequest;
use App\Models\Lead;
use Illuminate\Http\RedirectResponse;

class LeadOwnerController extends Controller
{
    public function update(AssignLeadRequest $request, Lead $lead, AssignLead $assign): RedirectResponse
    {
        $owner = $request->validated('owner_id');
        $assign($request->user(), $lead, $owner === null ? null : (int) $owner);

        return back()->with('status', 'Lead assignment updated.');
    }
}
