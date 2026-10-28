<?php

namespace App\Services\Leads;

use App\Enums\Priority;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Search, filter, sort and paginate the leads a user may see. Input is whitelisted, never interpolated. */
class LeadSearch
{
    /** Sortable columns: request value => column. */
    public const SORTS = [
        'created' => 'leads.created_at',
        'name' => 'leads.full_name',
        'stage_changed' => 'leads.stage_changed_at',
        'value' => 'leads.estimated_value',
        'last_contacted' => 'leads.last_contacted_at',
    ];

    /** @return array{q: string, stage: ?int, source: ?int, owner: ?string, priority: ?string, archived: bool, sort: string, dir: string} */
    public static function filters(Request $request, User $user): array
    {
        $level = LeadVisibility::level($user);

        return [
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
            'stage' => self::intOrNull($request->query('stage')),
            'source' => self::intOrNull($request->query('source')),
            // Agents only ever see their own leads, so an owner filter is meaningless (and ignored) for them.
            'owner' => $level === LeadVisibility::ALL ? self::ownerFilter($request->query('owner')) : null,
            'priority' => in_array($request->query('priority'), Priority::values(), true) ? (string) $request->query('priority') : null,
            'archived' => $request->boolean('archived') && LeadVisibility::seesArchived($user),
            'sort' => array_key_exists((string) $request->query('sort'), self::SORTS) ? (string) $request->query('sort') : 'created',
            'dir' => $request->query('dir') === 'asc' ? 'asc' : 'desc',
        ];
    }

    public static function paginate(Request $request, User $user): LengthAwarePaginator
    {
        $f = self::filters($request, $user);

        $query = LeadVisibility::scope(Lead::query(), $user, includeArchived: $f['archived'])
            ->with(['stage:id,name,key,outcome,color', 'source:id,name,key', 'owner:id,name']);

        if ($f['archived']) {
            $query->whereNotNull('leads.archived_at');
        }

        self::applySearch($query, $f['q']);

        $query
            ->when($f['stage'], fn (Builder $q, int $id) => $q->where('leads.pipeline_stage_id', $id))
            ->when($f['source'], fn (Builder $q, int $id) => $q->where('leads.lead_source_id', $id))
            ->when($f['priority'], fn (Builder $q, string $p) => $q->where('leads.priority', $p))
            ->when($f['owner'], function (Builder $q, string $owner) use ($user) {
                match ($owner) {
                    'unassigned' => $q->whereNull('leads.owner_id'),
                    'me' => $q->where('leads.owner_id', $user->getKey()),
                    default => $q->where('leads.owner_id', (int) $owner),
                };
            });

        // NULLs last for nullable sort columns; id as a stable tie-breaker so pagination never repeats/skips rows.
        $column = self::SORTS[$f['sort']];
        $query->orderByRaw("{$column} IS NULL")->orderBy($column, $f['dir'])->orderBy('leads.id', 'desc');

        return $query->paginate((int) config('crm.leads_per_page'))->withQueryString();
    }

    private static function applySearch(Builder $query, string $term): void
    {
        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '\\%_').'%';
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        $query->where(function (Builder $q) use ($like, $digits) {
            $q->where('leads.full_name', 'like', $like)
                ->orWhere('leads.company_name', 'like', $like)
                ->orWhere('leads.email', 'like', $like);

            if (strlen($digits) >= 3) {
                $q->orWhere('leads.phone_e164', 'like', '%'.$digits.'%');
            }
        });
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function ownerFilter(mixed $value): ?string
    {
        if (in_array($value, ['unassigned', 'me'], true)) {
            return $value;
        }

        return is_numeric($value) && (int) $value > 0 ? (string) (int) $value : null;
    }
}
