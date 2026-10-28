<?php

namespace App\Http\Controllers;

use App\Enums\TaskStatus;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Services\Leads\LeadOptions;
use App\Services\Leads\LeadVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PipelineController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Lead::class);
        $user = $request->user();
        $level = LeadVisibility::level($user);
        $limit = (int) config('crm.pipeline_cards_per_stage');

        $owner = $level === LeadVisibility::ALL && in_array($request->query('owner'), ['me', 'unassigned'], true) ? (string) $request->query('owner') : null;

        $visible = fn () => LeadVisibility::scope(Lead::query(), $user)
            ->when($owner === 'me', fn ($q) => $q->where('leads.owner_id', $user->getKey()))
            ->when($owner === 'unassigned', fn ($q) => $q->whereNull('leads.owner_id'));

        $counts = $visible()->select('leads.pipeline_stage_id', DB::raw('count(*) as c'))->groupBy('leads.pipeline_stage_id')->pluck('c', 'pipeline_stage_id');

        $columns = PipelineStage::query()->orderBy('position')->get()
            ->filter(fn (PipelineStage $s) => $s->is_active || ($counts[$s->id] ?? 0) > 0)
            ->map(function (PipelineStage $stage) use ($visible, $counts, $limit) {
                $cards = $visible()->where('leads.pipeline_stage_id', $stage->id)->with('owner:id,name')
                    ->orderByDesc('leads.stage_changed_at')->orderByDesc('leads.id')->limit($limit)->get();

                return ['stage' => $stage, 'cards' => $cards, 'count' => (int) ($counts[$stage->id] ?? 0)];
            });

        $overdue = FollowUpTask::query()
            ->whereIn('lead_id', $columns->pluck('cards')->flatten(1)->pluck('id'))
            ->where('status', TaskStatus::Open->value)->where('due_at', '<', now())
            ->select('lead_id', DB::raw('count(*) as c'))->groupBy('lead_id')->pluck('c', 'lead_id');

        return Inertia::render('Pipeline/Index', [
            'columns' => $columns->map(fn ($col) => [
                'stage' => ['id' => $col['stage']->id, 'name' => $col['stage']->name, 'key' => $col['stage']->key, 'outcome' => $col['stage']->outcome, 'color' => $col['stage']->color, 'inactive' => ! $col['stage']->is_active],
                'count' => $col['count'],
                'has_more' => $col['count'] > $col['cards']->count(),
                'cards' => $col['cards']->map(fn (Lead $l) => [
                    'id' => $l->public_id,
                    'full_name' => $l->full_name,
                    'company_name' => $l->company_name,
                    'priority' => $l->priority->value,
                    'estimated_value' => $l->estimated_value,
                    'owner' => $l->owner?->name,
                    'stage_id' => $l->pipeline_stage_id,
                    'last_contacted_at' => $l->last_contacted_at?->toIso8601String(),
                    'overdue_tasks' => (int) ($overdue[$l->id] ?? 0),
                    'can_move' => Gate::allows('changeStage', $l),
                ])->values(),
            ])->values(),
            'filters' => ['owner' => $owner],
            'options' => ['stages' => LeadOptions::stages(), 'can_filter_owner' => $level === LeadVisibility::ALL],
        ]);
    }
}
