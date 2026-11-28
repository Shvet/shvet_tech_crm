<?php

namespace Tests\Feature\Crm;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** CSV export, CSV import and bulk actions on leads. */
class LeadTransferTest extends TestCase
{
    private Tenant $t;

    private User $admin;

    private User $manager;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->t, $this->admin] = $this->provision('Alpha');
        $this->manager = $this->member($this->t, RoleName::SalesManager, userAttributes: ['email' => 'manager@alpha.test']);
        $this->agent = $this->member($this->t, RoleName::SalesAgent, userAttributes: ['email' => 'agent@alpha.test']);
    }

    private function as(User $user): static
    {
        return $this->actingInTenant($user, $this->t);
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('leads.csv', $content);
    }

    private function import(User $user, string $content, array $extra = [])
    {
        return $this->as($user)->post('/leads/import', [
            'file' => $this->csv($content),
            'lead_source_id' => $this->sourceId($this->t),
            ...$extra,
        ]);
    }

    /** @return list<array<string, string>> */
    private function parseCsv(string $body): array
    {
        $lines = array_map(fn ($l) => str_getcsv($l, escape: ''), array_filter(explode("\n", ltrim($body, "\xEF\xBB\xBF"))));
        $header = array_shift($lines);

        return array_map(fn ($cells) => array_combine($header, $cells), $lines);
    }

    // ------------------------------------------------------------------ export

    public function test_export_streams_the_visible_filtered_leads_as_csv_and_is_audited(): void
    {
        $this->lead($this->t, ['full_name' => 'Priya Nair', 'company_name' => 'Acme', 'owner_id' => $this->agent->id]);
        $this->lead($this->t, ['full_name' => 'Ravi Shah']);
        $this->lead($this->t, ['full_name' => 'Old Lead', 'archived_at' => now()]);

        $response = $this->as($this->manager)->get('/leads/export');
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename=leads-', (string) $response->headers->get('Content-Disposition'));

        $rows = $this->parseCsv($response->streamedContent());
        $this->assertEqualsCanonicalizing(['Priya Nair', 'Ravi Shah'], array_column($rows, 'name'), 'archived leads are not exported by default');
        $priya = collect($rows)->firstWhere('name', 'Priya Nair');
        $this->assertSame('agent@alpha.test', $priya['owner_email']);
        $this->assertSame('Acme', $priya['company']);
        $this->assertSame('New', $priya['stage']);

        $filtered = $this->parseCsv($this->as($this->manager)->get('/leads/export?q=ravi')->streamedContent());
        $this->assertSame(['Ravi Shah'], array_column($filtered, 'name'));

        $this->runIn($this->t, fn () => $this->assertSame(2, AuditLog::query()->where('action', 'leads.exported')->count()));
    }

    public function test_export_neutralizes_spreadsheet_formulas(): void
    {
        $this->lead($this->t, ['full_name' => '=HYPERLINK("http://evil.test","x")', 'company_name' => '+cmd|calc']);

        $rows = $this->parseCsv($this->as($this->admin)->get('/leads/export')->streamedContent());

        $this->assertSame("'=HYPERLINK(\"http://evil.test\",\"x\")", $rows[0]['name']);
        $this->assertSame("'+cmd|calc", $rows[0]['company']);
    }

    public function test_agents_cannot_export_and_nobody_exports_another_workspace(): void
    {
        $this->as($this->agent)->get('/leads/export')->assertForbidden();

        [$other] = $this->provision('Beta');
        $this->lead($other, ['full_name' => 'Beta Secret']);
        $this->lead($this->t, ['full_name' => 'Alpha Lead']);

        $rows = $this->parseCsv($this->as($this->admin)->get('/leads/export')->streamedContent());
        $this->assertSame(['Alpha Lead'], array_column($rows, 'name'));
    }

    // ------------------------------------------------------------------ import

    public function test_import_creates_valid_rows_and_reports_bad_ones_by_line(): void
    {
        $csv = "Name,Phone,E-mail,Company,Stage,Owner_Email,Priority,Value,Notes\n"
            ."Asha Patel,98765 43210,asha@example.test,Patel Textiles,Qualified,agent@alpha.test,high,\"25,000\",Met at expo\n"
            ."X,,,,,,,,\n"
            ."Bad Phone,12,,,,,,,\n"
            ."Unknown Owner,9811112222,,,,nobody@else.test,,,\n"
            ."\n"
            ."Mail Only,,mail.only@example.test,,,,,,\n";

        $this->import($this->admin, $csv)->assertRedirect('/leads/import')->assertSessionHas('import_result', function (array $r) {
            $this->assertSame(5, $r['total']);
            $this->assertSame(2, $r['created']);
            $this->assertSame(3, $r['failed']);
            $this->assertFalse($r['dry_run']);
            $this->assertSame([3, 4, 5], array_column($r['errors'], 'line'));
            $this->assertStringContainsString('name', implode(' ', $r['errors'][0]['messages']));
            $this->assertStringContainsString('phone', implode(' ', $r['errors'][1]['messages']));
            $this->assertStringContainsString('nobody@else.test', implode(' ', $r['errors'][2]['messages']));

            return true;
        });

        $this->runIn($this->t, function () {
            $asha = Lead::query()->where('full_name', 'Asha Patel')->sole();
            $this->assertSame('+919876543210', $asha->phone_e164);
            $this->assertSame('qualified', $asha->stage->key);
            $this->assertSame($this->agent->id, $asha->owner_id);
            $this->assertSame('high', $asha->priority->value);
            $this->assertSame('25000.00', $asha->estimated_value);
            $this->assertSame($this->admin->id, $asha->created_by);
            $this->assertSame('Met at expo', LeadActivity::query()->where('lead_id', $asha->id)->value('body'));
            $this->assertSame(2, Lead::query()->count());
            $this->assertSame(1, AuditLog::query()->where('action', 'leads.imported')->count());
        });
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->import($this->admin, "name,phone\nAsha Patel,9876543210\n", ['dry_run' => 1])
            ->assertSessionHas('import_result', fn (array $r) => $r['dry_run'] && $r['created'] === 1);

        $this->runIn($this->t, fn () => $this->assertSame(0, Lead::query()->count()));
    }

    public function test_duplicates_in_the_database_and_in_the_file_are_skipped_unless_allowed(): void
    {
        $this->lead($this->t, ['full_name' => 'Existing', 'phone_e164' => '+919876543210']);
        $csv = "name,phone,email\nSame Phone,9876543210,\nTwice,,twice@example.test\nTwice Again,,TWICE@example.test\n";

        $this->import($this->admin, $csv)->assertSessionHas('import_result', function (array $r) {
            $this->assertSame(1, $r['created']);
            $this->assertSame(2, $r['duplicates']);

            return true;
        });

        // Re-importing the same file is harmless.
        $this->import($this->admin, $csv)->assertSessionHas('import_result', fn (array $r) => $r['created'] === 0 && $r['duplicates'] === 3);

        $this->import($this->admin, "name,phone\nSame Phone,9876543210\n", ['allow_duplicates' => 1])
            ->assertSessionHas('import_result', fn (array $r) => $r['created'] === 1);

        $this->runIn($this->t, fn () => $this->assertSame(3, Lead::query()->count()));
    }

    public function test_an_exported_file_can_be_imported_into_another_workspace(): void
    {
        $this->lead($this->t, ['full_name' => 'Priya Nair', 'email' => 'priya@example.test', 'owner_id' => $this->agent->id]);
        $export = $this->as($this->admin)->get('/leads/export')->streamedContent();

        [$beta, $betaAdmin] = $this->provision('Beta');
        $this->actingInTenant($betaAdmin, $beta)->post('/leads/import', [
            'file' => $this->csv($export), 'lead_source_id' => $this->sourceId($beta),
        ])->assertSessionHas('import_result', function (array $r) {
            // The Alpha owner is not a Beta member: the row is refused rather than assigned to a stranger.
            $this->assertSame(1, $r['failed']);
            $this->assertStringContainsString('agent@alpha.test', implode(' ', $r['errors'][0]['messages']));

            return true;
        });
        $this->runIn($beta, fn () => $this->assertSame(0, Lead::query()->count()));
    }

    public function test_unusable_files_are_rejected_with_a_clear_message(): void
    {
        $this->import($this->admin, "phone,email\n9876543210,a@b.test\n")->assertSessionHasErrors('file');
        $this->import($this->admin, "name,phone\n")->assertSessionHasErrors('file');
        $this->import($this->admin, '')->assertSessionHasErrors('file');

        config(['crm.import_max_rows' => 2]);
        $this->import($this->admin, "name,phone\nA a,9876543210\nB b,9876543211\nC c,9876543212\n")->assertSessionHasErrors('file');
        $this->runIn($this->t, fn () => $this->assertSame(0, Lead::query()->count()), 'an oversized file imports nothing');
    }

    public function test_only_admins_can_import_and_the_source_must_belong_to_the_workspace(): void
    {
        $this->as($this->manager)->get('/leads/import')->assertForbidden();
        $this->import($this->manager, "name,phone\nA a,9876543210\n")->assertForbidden();
        $this->import($this->agent, "name,phone\nA a,9876543210\n")->assertForbidden();

        [$other] = $this->provision('Beta');
        $this->as($this->admin)->post('/leads/import', [
            'file' => $this->csv("name,phone\nA a,9876543210\n"), 'lead_source_id' => $this->sourceId($other),
        ])->assertSessionHasErrors('lead_source_id');

        $this->as($this->admin)->get('/leads/import')->assertOk();
    }

    // ------------------------------------------------------------------ bulk

    public function test_bulk_assign_stage_and_archive(): void
    {
        $a = $this->lead($this->t, ['full_name' => 'A one']);
        $b = $this->lead($this->t, ['full_name' => 'B two']);

        $this->as($this->manager)->post('/leads/bulk', ['ids' => [$a->public_id, $b->public_id], 'action' => 'assign', 'owner_id' => $this->agent->id])
            ->assertSessionHas('status', '2 leads updated.');
        $this->runIn($this->t, fn () => $this->assertSame([$this->agent->id, $this->agent->id], Lead::query()->orderBy('id')->pluck('owner_id')->all()));

        $won = $this->stageId($this->t, 'won');
        $this->as($this->manager)->post('/leads/bulk', ['ids' => [$a->public_id, $b->public_id], 'action' => 'stage', 'pipeline_stage_id' => $won])
            ->assertSessionHas('status', '2 leads updated.');
        $this->as($this->manager)->post('/leads/bulk', ['ids' => [$a->public_id], 'action' => 'stage', 'pipeline_stage_id' => $won])
            ->assertSessionHas('status', '0 leads updated. 1 skipped (already in that state, or not allowed).');

        $this->as($this->manager)->post('/leads/bulk', ['ids' => [$a->public_id], 'action' => 'assign', 'owner_id' => null])
            ->assertSessionHas('status', '1 lead updated.');

        $this->as($this->manager)->post('/leads/bulk', ['ids' => [$a->public_id, $b->public_id], 'action' => 'archive'])
            ->assertSessionHas('status', '2 leads updated.');

        $this->runIn($this->t, function () use ($won) {
            $this->assertSame(0, Lead::query()->whereNull('archived_at')->count());
            $this->assertSame([$won], Lead::query()->distinct()->pluck('pipeline_stage_id')->all());
            $this->assertNull(Lead::query()->where('full_name', 'A one')->value('owner_id'));
            $this->assertSame(1, AuditLog::query()->where('action', 'leads.bulk_archive')->count());
        });
    }

    public function test_bulk_skips_leads_from_other_workspaces_and_archived_leads(): void
    {
        [$other] = $this->provision('Beta');
        $foreign = $this->lead($other, ['full_name' => 'Foreign']);
        $archived = $this->lead($this->t, ['full_name' => 'Archived', 'archived_at' => now()]);
        $mine = $this->lead($this->t, ['full_name' => 'Mine']);

        $this->as($this->manager)->post('/leads/bulk', [
            'ids' => [$foreign->public_id, $archived->public_id, $mine->public_id], 'action' => 'assign', 'owner_id' => $this->agent->id,
        ])->assertSessionHas('status', '1 lead updated. 2 skipped (already in that state, or not allowed).');

        $this->runIn($other, fn () => $this->assertNull(Lead::query()->sole()->owner_id));
        $this->runIn($this->t, fn () => $this->assertNull(Lead::query()->where('full_name', 'Archived')->value('owner_id')));
    }

    public function test_bulk_requires_the_permission_and_valid_targets(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);

        $this->as($this->agent)->post('/leads/bulk', ['ids' => [$lead->public_id], 'action' => 'archive'])->assertForbidden();

        [$other, $otherAdmin] = $this->provision('Beta');
        $this->as($this->manager)->post('/leads/bulk', ['ids' => [$lead->public_id], 'action' => 'assign', 'owner_id' => $otherAdmin->id])
            ->assertSessionHasErrors('owner_id');
        $this->as($this->manager)->post('/leads/bulk', ['ids' => [$lead->public_id], 'action' => 'stage', 'pipeline_stage_id' => $this->stageId($other, 'won')])
            ->assertSessionHasErrors('pipeline_stage_id');
        $this->as($this->manager)->post('/leads/bulk', ['ids' => [$lead->public_id], 'action' => 'delete'])->assertSessionHasErrors('action');
        $this->as($this->manager)->post('/leads/bulk', ['ids' => array_fill(0, 101, $lead->public_id), 'action' => 'archive'])->assertSessionHasErrors('ids');

        $this->runIn($this->t, fn () => $this->assertSame($this->agent->id, Lead::query()->sole()->owner_id));
    }
}
