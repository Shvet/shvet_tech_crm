<?php

namespace App\Http\Controllers\Meta;

use App\Actions\Meta\UpdateFormMapping;
use App\Http\Controllers\Controller;
use App\Models\MetaConnection;
use App\Models\MetaFormMapping;
use App\Support\TenantRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MetaMappingController extends Controller
{
    public function update(Request $request, MetaFormMapping $metaMapping, UpdateFormMapping $update): RedirectResponse
    {
        Gate::authorize('manage', MetaConnection::class);

        $data = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'lead_source_id' => ['nullable', 'integer', TenantRules::exists('lead_sources', activeOnly: true)],
            'pipeline_stage_id' => ['nullable', 'integer', TenantRules::exists('pipeline_stages', activeOnly: true)],
            'default_owner_id' => ['nullable', 'integer', TenantRules::activeMember()],
            'field_map' => ['nullable', 'array'],
            'field_map.*' => ['nullable', 'string', 'max:100'],
        ]);

        $update($metaMapping, $data);

        return back()->with('status', 'Form mapping saved.');
    }
}
