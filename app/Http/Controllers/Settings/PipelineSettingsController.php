<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Pipeline\ManagePipelineStages;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Support\Facades\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Inertia\Inertia;
use Inertia\Response;

/** Pipeline stage settings (pipeline.manage). Stage ids are looked up inside the current tenant only. */
class PipelineSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeManage($request);

        $counts = Lead::query()->whereNull('archived_at')->selectRaw('pipeline_stage_id, count(*) as c')
            ->groupBy('pipeline_stage_id')->pluck('c', 'pipeline_stage_id');

        return Inertia::render('Settings/Pipeline', [
            'stages' => PipelineStage::query()->orderBy('position')->orderBy('id')->get()->map(fn (PipelineStage $s) => [
                'id' => $s->id, 'name' => $s->name, 'key' => $s->key, 'outcome' => $s->outcome,
                'is_active' => $s->is_active, 'leads' => (int) ($counts[$s->id] ?? 0),
            ])->values(),
        ]);
    }

    public function store(Request $request, ManagePipelineStages $manage): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100', $this->uniqueName()],
            'outcome' => ['required', Rule::in(ManagePipelineStages::OUTCOMES)],
        ]);

        $manage->create(trim($data['name']), $data['outcome']);

        return back()->with('status', 'Stage added at the end. Move it into place if needed.');
    }

    public function update(Request $request, int $stage, ManagePipelineStages $manage): RedirectResponse
    {
        $this->authorizeManage($request);
        $model = PipelineStage::query()->findOrFail($stage);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:100', $this->uniqueName()->ignore($model->getKey())],
            'outcome' => ['sometimes', 'required', Rule::in(ManagePipelineStages::OUTCOMES)],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        $manage->update($model, $data);

        return back()->with('status', 'Stage updated.');
    }

    public function reorder(Request $request, ManagePipelineStages $manage): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $manage->reorder(array_map('intval', $data['ids']));

        return back()->with('status', 'Stage order saved.');
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->checkPermissionTo(Permission::PipelineManage->value), 403);
    }

    private function uniqueName(): Unique
    {
        return Rule::unique('pipeline_stages', 'name')->where('tenant_id', Tenancy::idOrFail());
    }
}
