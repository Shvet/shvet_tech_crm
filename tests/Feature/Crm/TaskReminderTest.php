<?php

namespace Tests\Feature\Crm;

use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\TaskReminderDigest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TaskReminderTest extends TestCase
{
    private Tenant $t;

    private User $admin;

    private User $agent;

    private User $agent2;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        [$this->t, $this->admin] = $this->provision('Alpha'); // Asia/Kolkata
        $this->agent = $this->member($this->t, RoleName::SalesAgent);
        $this->agent2 = $this->member($this->t, RoleName::SalesAgent);
        config(['crm.reminder_hour' => 8]);
    }

    private function at(string $local): void
    {
        $this->travelTo(CarbonImmutable::parse($local, 'Asia/Kolkata'));
    }

    private function task(Lead $lead, string $dueLocal, User $assignee, array $attrs = []): FollowUpTask
    {
        return $this->runIn($this->t, fn () => FollowUpTask::query()->create([
            'lead_id' => $lead->id, 'title' => 'Call back', 'priority' => 'medium',
            'due_at' => CarbonImmutable::parse($dueLocal, 'Asia/Kolkata')->utc(),
            'assigned_to' => $assignee->id, 'created_by' => $this->admin->id, ...$attrs,
        ]));
    }

    private function run_(): void
    {
        $this->artisan('crm:send-task-reminders')->assertSuccessful();
    }

    public function test_members_get_one_digest_a_day_listing_overdue_and_due_today_only(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id, 'full_name' => 'Priya Nair']);
        $this->task($lead, '2026-10-27 15:00', $this->agent, ['title' => 'Overdue call']);
        $this->task($lead, '2026-10-28 17:00', $this->agent, ['title' => 'Today call']);
        $this->task($lead, '2026-10-29 10:00', $this->agent, ['title' => 'Tomorrow call']);
        $this->task($lead, '2026-10-27 10:00', $this->agent, ['title' => 'Done call', 'status' => 'completed', 'completed_at' => now()]);

        $this->at('2026-10-28 07:59');
        $this->run_();
        Notification::assertNothingSent();

        $this->at('2026-10-28 09:00');
        $this->run_();
        Notification::assertSentTo($this->agent, TaskReminderDigest::class, function (TaskReminderDigest $n) {
            $this->assertSame('Alpha', $n->workspace);
            $this->assertSame(2, $n->total);
            $this->assertSame(1, $n->overdue);
            $this->assertSame(['Overdue call', 'Today call'], array_column($n->items, 'title'));
            $this->assertSame('Priya Nair', $n->items[0]['lead']);
            $mail = $n->toMail($this->agent);
            $this->assertStringContainsString('Alpha: 2 follow-ups', $mail->subject);

            return true;
        });
        Notification::assertNotSentTo($this->agent2, TaskReminderDigest::class);

        // Later the same day: nothing new.
        $this->at('2026-10-28 15:00');
        $this->run_();
        Notification::assertSentToTimes($this->agent, TaskReminderDigest::class, 1);

        // Next morning: a new digest (the "today" task is now overdue).
        $this->at('2026-10-29 08:05');
        $this->run_();
        Notification::assertSentToTimes($this->agent, TaskReminderDigest::class, 2);
        $this->assertSame(2, DB::table('task_reminder_digests')->where('user_id', $this->agent->id)->count());
    }

    public function test_archived_leads_opted_out_members_and_inactive_members_get_nothing(): void
    {
        $archived = $this->lead($this->t, ['owner_id' => $this->agent->id, 'archived_at' => now()]);
        $this->task($archived, '2026-10-28 09:00', $this->agent);

        $lead = $this->lead($this->t, ['owner_id' => $this->agent2->id]);
        $this->task($lead, '2026-10-28 09:00', $this->agent2);
        $this->runIn($this->t, fn () => TenantMembership::query()->where('user_id', $this->agent2->id)->update(['task_reminders' => false]));

        $suspended = $this->member($this->t, RoleName::SalesManager, MembershipStatus::Suspended);
        $this->task($lead, '2026-10-28 09:00', $suspended);

        $this->at('2026-10-28 10:00');
        $this->run_();
        Notification::assertNothingSent();
    }

    public function test_each_workspace_uses_its_own_clock(): void
    {
        [$ny, $nyAdmin] = $this->provision('Gamma');
        $ny->forceFill(['timezone' => 'America/New_York'])->save();
        $lead = $this->lead($ny, ['owner_id' => $nyAdmin->id]);
        $this->runIn($ny, fn () => FollowUpTask::query()->create([
            'lead_id' => $lead->id, 'title' => 'NY call', 'priority' => 'medium',
            'due_at' => CarbonImmutable::parse('2026-10-27 12:00', 'America/New_York')->utc(),
            'assigned_to' => $nyAdmin->id, 'created_by' => $nyAdmin->id,
        ]));

        // 06:00 in New York is already 15:30 in Kolkata: the New York workspace must still wait for its own 08:00.
        $this->travelTo(CarbonImmutable::parse('2026-10-28 06:00', 'America/New_York'));
        $this->run_();
        Notification::assertNotSentTo($nyAdmin, TaskReminderDigest::class);

        $this->travelTo(CarbonImmutable::parse('2026-10-28 08:30', 'America/New_York'));
        $this->run_();
        Notification::assertSentTo($nyAdmin, TaskReminderDigest::class);
    }

    public function test_members_switch_their_own_reminders_off_and_on_from_the_follow_ups_page(): void
    {
        $this->actingInTenant($this->agent, $this->t)->get('/tasks')->assertInertia(fn ($p) => $p->where('reminders.enabled', true)->where('reminders.hour', 8));

        $this->actingInTenant($this->agent, $this->t)->patch('/tasks/reminders', ['enabled' => false])->assertSessionHas('status', 'Daily follow-up e-mails are off.');
        $this->runIn($this->t, function () {
            $this->assertFalse(TenantMembership::query()->where('user_id', $this->agent->id)->value('task_reminders'));
            $this->assertTrue(TenantMembership::query()->where('user_id', $this->agent2->id)->value('task_reminders'), 'only the caller changes');
        });

        // The preference is per workspace: Beta is unaffected.
        [$beta] = $this->provision('Beta');
        $this->addMembership($beta, $this->agent, RoleName::SalesAgent);
        $this->runIn($beta, fn () => $this->assertTrue(TenantMembership::query()->where('user_id', $this->agent->id)->value('task_reminders')));

        $this->actingInTenant($this->agent, $this->t)->patch('/tasks/reminders', ['enabled' => true])->assertSessionHas('status', 'Daily follow-up e-mails are on.');
    }
}
