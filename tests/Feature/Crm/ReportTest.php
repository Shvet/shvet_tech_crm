<?php

namespace Tests\Feature\Crm;

use App\Enums\RoleName;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ReportTest extends TestCase
{
    private Tenant $t;

    private User $admin;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->t, $this->admin] = $this->provision('Alpha'); // Asia/Kolkata
        $this->agent = $this->member($this->t, RoleName::SalesAgent, userAttributes: ['name' => 'Asha Agent']);
        $this->travelTo(CarbonImmutable::parse('2026-11-28 12:00', 'Asia/Kolkata'));
    }

    private function report(string $query = '', ?User $user = null): array
    {
        return $this->actingInTenant($user ?? $this->admin, $this->t)->get('/reports'.$query)->assertOk()->viewData('page')['props']['report'];
    }

    public function test_summary_and_breakdowns_for_the_default_last_30_days(): void
    {
        $web = $this->sourceId($this->t, 'website');
        $manual = $this->sourceId($this->t, 'manual');
        $days = fn (int $n) => now()->subDays($n);

        // Created in range, won in range after 10 days, website, owned by the agent.
        $this->lead($this->t, ['lead_source_id' => $web, 'owner_id' => $this->agent->id, 'estimated_value' => '1000.00', 'created_at' => $days(20), 'stage_changed_at' => $days(10)], 'won');
        // Created in range, lost in range, website.
        $this->lead($this->t, ['lead_source_id' => $web, 'created_at' => $days(5), 'stage_changed_at' => $days(1)], 'lost');
        // Created in range, still open, manual.
        $this->lead($this->t, ['lead_source_id' => $manual, 'estimated_value' => '500.00', 'created_at' => $days(3)], 'qualified');
        // Created long ago, won long ago: outside the range entirely, but "open now" ignores ranges.
        $this->lead($this->t, ['lead_source_id' => $manual, 'created_at' => $days(200), 'stage_changed_at' => $days(100)], 'won');
        // Archived and other-tenant leads never count.
        $this->lead($this->t, ['lead_source_id' => $web, 'created_at' => $days(2), 'archived_at' => now()]);
        [$beta] = $this->provision('Beta');
        $this->lead($beta, ['created_at' => $days(2)]);

        $r = $this->report();

        $this->assertSame('2026-10-30', $r['range']['from']);
        $this->assertSame('2026-11-28', $r['range']['to']);
        $this->assertSame(3, $r['summary']['created']);
        $this->assertSame(1, $r['summary']['won']);
        $this->assertSame(1, $r['summary']['lost']);
        $this->assertEquals(50.0, $r['summary']['win_rate']);
        $this->assertEquals(10.0, $r['summary']['avg_days_to_win']);
        $this->assertEquals(1000, (float) $r['summary']['won_value']);

        $website = collect($r['by_source'])->firstWhere('name', 'Website');
        $this->assertSame([2, 1, 1], [$website['created'], $website['won'], $website['lost']]);
        $manualRow = collect($r['by_source'])->firstWhere('name', 'Manual entry');
        $this->assertSame([1, 0, 1], [$manualRow['created'], $manualRow['won'], $manualRow['open']]);

        $asha = collect($r['by_owner'])->firstWhere('name', 'Asha Agent');
        $this->assertSame(1, $asha['won']);
        $this->assertNotNull(collect($r['by_owner'])->firstWhere('name', 'Unassigned'));

        $qualified = collect($r['funnel'])->firstWhere('name', 'Qualified');
        $this->assertSame(1, $qualified['count']);
        $this->assertEquals(500, (float) $qualified['value']);
    }

    public function test_presets_custom_ranges_and_local_day_boundaries(): void
    {
        // 23:30 IST on 1 Nov is 18:00 UTC: it belongs to 1 Nov for this workspace.
        $this->lead($this->t, ['created_at' => CarbonImmutable::parse('2026-11-01 23:30', 'Asia/Kolkata')->utc()]);
        $this->lead($this->t, ['created_at' => CarbonImmutable::parse('2026-11-02 00:30', 'Asia/Kolkata')->utc()]);

        $this->assertSame(1, $this->report('?from=2026-11-01&to=2026-11-01')['summary']['created']);
        $this->assertSame(1, $this->report('?from=2026-11-02&to=2026-11-02')['summary']['created']);
        $this->assertSame(2, $this->report('?from=2026-11-02&to=2026-11-01')['summary']['created'], 'reversed dates are swapped');

        $page = $this->actingInTenant($this->admin, $this->t)->get('/reports?range=7')->viewData('page')['props'];
        $this->assertSame('7', $page['preset']);
        $this->assertSame('2026-11-22', $page['report']['range']['from']);

        $this->assertSame('30', $this->actingInTenant($this->admin, $this->t)->get('/reports?from=garbage&to=2026-13-45')->viewData('page')['props']['preset']);
        $this->assertSame('2024-11-27', $this->report('?from=2020-01-01&to=2026-11-28')['range']['from'], 'capped at two years');
    }

    public function test_only_roles_with_reports_view_can_open_reports(): void
    {
        $manager = $this->member($this->t, RoleName::SalesManager);
        $this->actingInTenant($manager, $this->t)->get('/reports')->assertOk();
        $this->actingInTenant($this->agent, $this->t)->get('/reports')->assertForbidden();
    }
}
