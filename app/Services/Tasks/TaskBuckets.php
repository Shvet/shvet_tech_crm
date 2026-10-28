<?php

namespace App\Services\Tasks;

use App\Enums\TaskStatus;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\LeadVisibility;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Follow-up buckets, disjoint and computed in the tenant's timezone:
 *   overdue   open and past its due time
 *   today     open, due from now until the end of the tenant's local day
 *   upcoming  open, due after today
 * Tasks are visible exactly when their (non-archived, for everyone) lead is visible to the user.
 */
class TaskBuckets
{
    public const OVERDUE = 'overdue';

    public const TODAY = 'today';

    public const UPCOMING = 'upcoming';

    public const COMPLETED = 'completed';

    /** All tasks the user may see (any status), lead non-archived. */
    public static function visible(User $user): Builder
    {
        return FollowUpTask::query()->whereIn(
            'follow_up_tasks.lead_id',
            LeadVisibility::scope(Lead::query(), $user)->select('leads.id'),
        );
    }

    public static function open(User $user): Builder
    {
        return self::visible($user)->where('follow_up_tasks.status', TaskStatus::Open->value);
    }

    public static function bucket(Builder $query, string $bucket, ?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now();
        [, $endOfToday] = TenantTime::todayBounds($now);

        return match ($bucket) {
            self::OVERDUE => $query->where('follow_up_tasks.due_at', '<', $now),
            self::TODAY => $query->where('follow_up_tasks.due_at', '>=', $now)->where('follow_up_tasks.due_at', '<=', $endOfToday),
            self::UPCOMING => $query->where('follow_up_tasks.due_at', '>', $endOfToday),
            default => $query,
        };
    }

    /** @return array{overdue: int, today: int, upcoming: int} */
    public static function counts(User $user, ?int $assignedTo = null, ?CarbonImmutable $now = null): array
    {
        $count = fn (string $bucket) => self::bucket(
            self::open($user)->when($assignedTo, fn ($q) => $q->where('follow_up_tasks.assigned_to', $assignedTo)),
            $bucket,
            $now,
        )->count();

        return [
            self::OVERDUE => $count(self::OVERDUE),
            self::TODAY => $count(self::TODAY),
            self::UPCOMING => $count(self::UPCOMING),
        ];
    }
}
