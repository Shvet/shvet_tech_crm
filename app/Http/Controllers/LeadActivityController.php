<?php

namespace App\Http\Controllers;

use App\Actions\Leads\LogActivity;
use App\Enums\ActivityType;
use App\Http\Requests\StoreActivityRequest;
use App\Models\Lead;
use Illuminate\Http\RedirectResponse;

class LeadActivityController extends Controller
{
    public function store(StoreActivityRequest $request, Lead $lead, LogActivity $logActivity): RedirectResponse
    {
        $logActivity(
            $request->user(),
            $lead,
            ActivityType::from($request->validated('type')),
            $request->validated('subject'),
            $request->validated('body'),
            $request->occurredAt(),
        );

        return back()->with('status', 'Activity recorded.');
    }
}
