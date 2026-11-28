<?php

namespace App\Services\Reports;

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\PipelineStage;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Leads\LeadVisibility;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Sales report for a date range in the tenant's timezone, over the (non-archived) leads the user can see.
 *
 *   created   leads created in the range
 *   won/lost  leads that are CURRENTLY in a won/lost stage and moved there in the range (stage_changed_at)
 *   win rate  won / (won + lost) for the range
 *   by source / by owner: the same numbers broken down; "open now" is a snapshot, not range-bound
 *   days to win: average days from creation to the winning move, for leads won in the range
 */
class SalesReport
{
    /** @return array<string, mixed> */
    public static function for(User $user, CarbonImmutable $fromLocal, CarbonImmutable $toLocal): array
    {
        $tz = TenantTime::timezone();
        $from = CarbonImmutable::parse($fromLocal->toDateString(), $tz)->startOfDay()->utc();
        $to = CarbonImmutable::parse($toLocal->toDateString(), $tz)->endOfDay()->utc();

        $stageOutcome = PipelineStage::query()->pluck('outcome', 'id');
        $wonIds = $stageOutcome->filter(fn ($o) => $o === 'won')->keys()->all();
        $lostIds = $stageOutcome->filter(fn ($o) => $o === 'lost')->keys()->all();
        $openIds = $stageOutcome->filter(fn ($o) => $o === 'open')->keys()->all();

        $leads = fn (): Builder => LeadVisibility::scope(Lead::query(), $user);
        $created = fn (): Builder => $leads()->whereBetween('leads.created_at', [$from, $to]);
        $closed = fn (array $stageIds): Builder => $leads()->whereIn('leads.pipeline_stage_id', $stageIds ?: [0])
            ->whereBetween('leads.stage_changed_at', [$from, $to]);

        $won = (int) $closed($wonIds)->count();
        $lost = (int) $closed($lostIds)->count();
        $avgDays = $closed($wonIds)->toBase()->selectRaw('avg(timestampdiff(hour, leads.created_at, leads.stage_changed_at)) / 24 as d')->value('d');

        $group = function (string $column) use ($created, $closed, $leads, $wonIds, $lostIds, $openIds): array {
            $rows = [];
            $add = function ($query, string $metric, bool $withValue = false) use (&$rows, $column) {
                $select = [DB::raw("{$column} as k"), DB::raw('count(*) as c')];
                if ($withValue) {
                    $select[] = DB::raw('coalesce(sum(leads.estimated_value), 0) as v');
                }
                foreach ($query->select($select)->groupBy($column)->get() as $r) {
                    $key = $r->k === null ? 'none' : (string) $r->k;
                    $rows[$key][$metric] = (int) $r->c;
                    if ($withValue) {
                        $rows[$key][$metric.'_value'] = (string) $r->v;
                    }
                }
            };
            $add($created(), 'created');
            $add($closed($wonIds), 'won', true);
            $add($closed($lostIds), 'lost');
            $add($leads()->whereIn('leads.pipeline_stage_id', $openIds ?: [0]), 'open', true);

            return $rows;
        };

        $sourceNames = LeadSource::query()->pluck('name', 'id');
        $ownerNames = TenantMembership::query()->with('user:id,name')->get()
            ->mapWithKeys(fn (TenantMembership $m) => [(string) $m->user_id => $m->user->name ?? 'Former member']);

        $shape = function (array $rows, callable $label): array {
            $out = [];
            foreach ($rows as $key => $r) {
                $won = $r['won'] ?? 0;
                $lost = $r['lost'] ?? 0;
                $out[] = [
                    'key' => (string) $key,
                    'name' => $label((string) $key),
                    'created' => $r['created'] ?? 0,
                    'won' => $won,
                    'lost' => $lost,
                    'won_value' => $r['won_value'] ?? '0',
                    'open' => $r['open'] ?? 0,
                    'open_value' => $r['open_value'] ?? '0',
                    'win_rate' => $won + $lost > 0 ? round($won / ($won + $lost) * 100, 1) : null,
                ];
            }
            usort($out, fn ($a, $b) => [$b['created'], $b['won']] <=> [$a['created'], $a['won']]);

            return $out;
        };

        $funnel = $leads()->whereIn('leads.pipeline_stage_id', $openIds ?: [0])
            ->select('leads.pipeline_stage_id', DB::raw('count(*) as c'), DB::raw('coalesce(sum(leads.estimated_value), 0) as v'))
            ->groupBy('leads.pipeline_stage_id')->get()->keyBy('pipeline_stage_id');

        return [
            'range' => ['from' => $fromLocal->toDateString(), 'to' => $toLocal->toDateString()],
            'summary' => [
                'created' => (int) $created()->count(),
                'won' => $won,
                'lost' => $lost,
                'won_value' => (string) $closed($wonIds)->sum('leads.estimated_value'),
                'win_rate' => $won + $lost > 0 ? round($won / ($won + $lost) * 100, 1) : null,
                'avg_days_to_win' => $avgDays === null ? null : round((float) $avgDays, 1),
            ],
            'by_source' => $shape($group('leads.lead_source_id'), fn (string $k) => $sourceNames[(int) $k] ?? 'Unknown'),
            'by_owner' => $shape($group('leads.owner_id'), fn (string $k) => $k === 'none' ? 'Unassigned' : ($ownerNames[$k] ?? 'Former member')),
            'funnel' => PipelineStage::query()->whereIn('id', $openIds ?: [0])->orderBy('position')->get()
                ->filter(fn (PipelineStage $s) => $s->is_active || isset($funnel[$s->id]))
                ->map(fn (PipelineStage $s) => [
                    'name' => $s->name,
                    'count' => (int) ($funnel[$s->id]->c ?? 0),
                    'value' => (string) ($funnel[$s->id]->v ?? '0'),
                ])->values()->all(),
        ];
    }
}
