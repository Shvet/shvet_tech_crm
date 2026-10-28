<?php

namespace App\Services\Dashboard;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Leads\LeadVisibility;
use App\Services\Tasks\TaskBuckets;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Every number here is derived from LeadVisibility, so a Sales Agent's dashboard is computed from their own
 * leads only, and nothing can include another tenant's rows (tenant scope applies underneath).
 */
class DashboardMetrics
{
    /** @return array<string, mixed> */
    public static function for(User $user, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $leads = fn () => LeadVisibility::scope(Lead::query(), $user);

        $byStage = $leads()->select('leads.pipeline_stage_id', DB::raw('count(*) as c'), DB::raw('coalesce(sum(leads.estimated_value), 0) as v'))
            ->groupBy('leads.pipeline_stage_id')->get()->keyBy('pipeline_stage_id');

        // Active stages always; a deactivated stage still shows while leads sit in it, so totals stay truthful.
        $stages = PipelineStage::query()->orderBy('position')->get()
            ->filter(fn (PipelineStage $s) => $s->is_active || isset($byStage[$s->id]))
            ->map(fn (PipelineStage $s) => [
                'id' => $s->id, 'name' => $s->name, 'key' => $s->key, 'outcome' => $s->outcome, 'color' => $s->color,
                'inactive' => ! $s->is_active,
                'count' => (int) ($byStage[$s->id]->c ?? 0),
                'value' => (string) ($byStage[$s->id]->v ?? '0'),
            ])->values();

        $outcomeCount = fn (string $outcome) => (int) $stages->where('outcome', $outcome)->sum('count');

        $recent = LeadActivity::query()
            ->whereIn('lead_id', $leads()->select('leads.id'))
            ->with(['lead:id,public_id,full_name', 'user:id,name'])
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(8)->get()
            ->map(fn (LeadActivity $a) => [
                'id' => $a->id,
                'type' => $a->type->value,
                'subject' => $a->subject,
                'occurred_at' => $a->occurred_at->toIso8601String(),
                'user' => $a->user?->name,
                'lead' => ['id' => $a->lead->public_id, 'name' => $a->lead->full_name],
            ]);

        return [
            'totals' => [
                'leads' => (int) $stages->sum('count'),
                'new_this_week' => $leads()->where('leads.created_at', '>=', $now->subDays(7))->count(),
                'open' => $outcomeCount('open'),
                'won' => $outcomeCount('won'),
                'lost' => $outcomeCount('lost'),
                'open_value' => (string) $stages->where('outcome', 'open')->sum(fn ($s) => (float) $s['value']),
                'unassigned' => LeadVisibility::level($user) === LeadVisibility::ALL ? $leads()->whereNull('leads.owner_id')->count() : null,
            ],
            'stages' => $stages,
            'recent_activity' => $recent,
            'tasks' => TaskBuckets::counts($user, now: $now),
        ];
    }
}
