<?php

namespace App\Actions\Tasks;

use App\Enums\MembershipStatus;
use App\Models\FollowUpTask;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\TaskReminderDigest;
use App\Services\Tasks\TaskBuckets;
use App\Support\Facades\Tenancy;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Sends each active member of the CURRENT tenant one e-mail a day listing their open follow-ups that are overdue or
 * due today, once the tenant's local clock has reached crm.reminder_hour.
 *
 * - Only tasks assigned to the member on leads they can still see (TaskBuckets visibility) are listed.
 * - Members can turn it off per workspace (tenant_memberships.task_reminders).
 * - Idempotent: a (tenant, user, local date) row is claimed before the mail is queued, so a second run the same day,
 *   or two schedulers at once, never send twice. Nobody with nothing due gets an e-mail.
 */
class SendTaskReminderDigests
{
    public const MAX_LISTED = 20;

    /** @return int digests sent */
    public function __invoke(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $local = $now->setTimezone(TenantTime::timezone());
        if ($local->hour < (int) config('crm.reminder_hour', 8)) {
            return 0;
        }

        $tenant = Tenancy::currentOrFail();
        [, $endOfToday] = TenantTime::todayBounds($now);
        $sent = 0;

        $memberships = TenantMembership::query()->where('status', MembershipStatus::Active->value)
            ->where('task_reminders', true)->with('user')->get();

        foreach ($memberships as $membership) {
            /** @var User|null $user */
            $user = $membership->user;
            if ($user === null || ! $user->is_active) {
                continue;
            }
            $user->forgetTenantCaches();

            $due = TaskBuckets::open($user)
                ->where('follow_up_tasks.assigned_to', $user->getKey())
                ->where('follow_up_tasks.due_at', '<=', $endOfToday)
                ->with('lead:id,public_id,full_name')
                ->orderBy('follow_up_tasks.due_at')->orderBy('follow_up_tasks.id');

            $count = (clone $due)->count();
            if ($count === 0) {
                continue;
            }

            if (! $this->claim($tenant->getKey(), (int) $user->getKey(), $local->toDateString(), $count, $now)) {
                continue; // already sent today
            }

            /** @var Collection<int, FollowUpTask> $tasks */
            $tasks = $due->limit(self::MAX_LISTED)->get();
            $overdue = (clone TaskBuckets::open($user))->where('follow_up_tasks.assigned_to', $user->getKey())
                ->where('follow_up_tasks.due_at', '<', $now)->count();

            $user->notify(new TaskReminderDigest($tenant->name, $tasks, $count, $overdue, TenantTime::timezone()));
            $sent++;
        }

        return $sent;
    }

    private function claim(int $tenantId, int $userId, string $localDate, int $count, CarbonImmutable $now): bool
    {
        try {
            DB::table('task_reminder_digests')->insert([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'local_date' => $localDate,
                'task_count' => min($count, 65535), 'sent_at' => $now,
            ]);

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }
    }
}
