<?php

namespace Tests\Feature\Crm;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadStageHistory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeadManagementTest extends TestCase
{
    private Tenant $t;

    private User $admin;

    private User $manager;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->t, $this->admin] = $this->provision('Alpha');
        $this->manager = $this->member($this->t, RoleName::SalesManager);
        $this->agent = $this->member($this->t, RoleName::SalesAgent);
    }

    private function as(User $user): static
    {
        return $this->actingInTenant($user, $this->t);
    }

    private function leads(): Collection
    {
        return $this->runIn($this->t, fn () => Lead::query()->orderBy('id')->get());
    }

    // ------------------------------------------------------------------ create

    public function test_creating_a_lead_stores_normalized_data_history_note_and_redirects_to_it(): void
    {
        $payload = $this->leadPayload($this->t, ['notes' => 'Met at the Surat expo.', 'email' => '  ASHA@Example.TEST ']);

        $response = $this->as($this->manager)->post('/leads', $payload);

        $lead = $this->leads()->first();
        $response->assertRedirect(route('leads.show', $lead))->assertSessionHas('status', 'Lead created.');
        $this->assertSame('+919876543210', $lead->phone_e164);
        $this->assertSame('asha@example.test', $lead->email);
        $this->assertSame('125000.50', $lead->estimated_value);
        $this->assertSame('high', $lead->priority->value);
        $this->assertSame($this->manager->id, $lead->created_by);
        $this->assertNotNull($lead->stage_changed_at);

        $this->runIn($this->t, function () use ($lead) {
            $this->assertSame('new', $lead->stage->key, 'defaults to the first active stage');
            $history = LeadStageHistory::query()->where('lead_id', $lead->id)->get();
            $this->assertCount(1, $history);
            $this->assertNull($history[0]->from_stage_id);
            $this->assertSame($lead->pipeline_stage_id, $history[0]->to_stage_id);
            $this->assertSame($this->manager->id, $history[0]->changed_by);
            $this->assertSame('Met at the Surat expo.', LeadActivity::query()->where('lead_id', $lead->id)->first()->body);
        });
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing name' => [['full_name' => ''], 'full_name'],
            'one-letter name' => [['full_name' => 'A'], 'full_name'],
            'name too long' => [['full_name' => str_repeat('x', 256)], 'full_name'],
            'no contact method' => [['phone' => '', 'email' => ''], 'phone'],
            'invalid phone' => [['phone' => '12345'], 'phone'],
            'letters in phone' => [['phone' => 'call me'], 'phone'],
            'invalid email' => [['email' => 'not-an-email'], 'email'],
            'missing source' => [['lead_source_id' => null], 'lead_source_id'],
            'unknown source' => [['lead_source_id' => 999999], 'lead_source_id'],
            'bad priority' => [['priority' => 'urgent'], 'priority'],
            'negative value' => [['estimated_value' => '-5'], 'estimated_value'],
            'text value' => [['estimated_value' => 'lots'], 'estimated_value'],
            'too many decimals' => [['estimated_value' => '10.999'], 'estimated_value'],
            'absurd value' => [['estimated_value' => '1e15'], 'estimated_value'],
            'bad token' => [['submission_token' => 'not-a-uuid'], 'submission_token'],
            'huge notes' => [['notes' => str_repeat('n', 5001)], 'notes'],
            'company too long' => [['company_name' => str_repeat('c', 256)], 'company_name'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_input_is_rejected_and_nothing_is_stored(array $override, string $errorKey): void
    {
        $this->as($this->manager)->post('/leads', $this->leadPayload($this->t, $override))->assertSessionHasErrors($errorKey);

        $this->assertCount(0, $this->leads());
    }

    public function test_either_phone_or_email_alone_is_enough(): void
    {
        $this->as($this->manager)->post('/leads', $this->leadPayload($this->t, ['email' => '', 'submission_token' => (string) Str::uuid()]))->assertSessionHasNoErrors();
        $this->as($this->manager)->post('/leads', $this->leadPayload($this->t, ['phone' => '', 'email' => 'only@example.test', 'submission_token' => (string) Str::uuid()]))->assertSessionHasNoErrors();

        $this->assertCount(2, $this->leads());
    }

    public function test_international_numbers_are_normalized_to_e164(): void
    {
        $this->as($this->manager)->post('/leads', $this->leadPayload($this->t, ['phone' => '+1 (415) 555-2671', 'email' => '']))->assertSessionHasNoErrors();

        $this->assertSame('+14155552671', $this->leads()->first()->phone_e164);
    }

    public function test_markup_in_text_fields_is_stored_verbatim_and_never_interpreted(): void
    {
        $xss = '<script>alert(1)</script>';
        $this->as($this->manager)->post('/leads', $this->leadPayload($this->t, ['full_name' => "Eve $xss", 'company_name' => $xss]))->assertSessionHasNoErrors();

        $lead = $this->leads()->first();
        $this->assertSame("Eve $xss", $lead->full_name); // output escaping is React's job; the API returns data, not HTML
        $this->as($this->manager)->get("/leads/{$lead->public_id}")->assertInertia(fn ($p) => $p->where('lead.company_name', $xss));
    }

    // ------------------------------------------------------------------ double submit / duplicates

    public function test_double_submitting_the_same_form_creates_one_lead(): void
    {
        $payload = $this->leadPayload($this->t);

        $first = $this->as($this->manager)->post('/leads', $payload);
        $second = $this->as($this->manager)->post('/leads', $payload);

        $this->assertCount(1, $this->leads());
        $second->assertRedirect($first->headers->get('Location'))->assertSessionHas('status', 'Lead was already created.');
    }

    public function test_the_idempotency_token_does_not_leak_or_hijack_another_users_lead(): void
    {
        $payload = $this->leadPayload($this->t);
        $this->as($this->manager)->post('/leads', $payload);

        // Another user replaying the manager's token must not receive the manager's lead.
        $this->as($this->admin)->post('/leads', [...$payload, 'confirm_duplicate' => true])->assertSessionHasNoErrors();

        $leads = $this->leads();
        $this->assertCount(2, $leads, 'the token is scoped to its creator: the admin\'s submission is a separate lead');
        $this->assertSame([$this->manager->id, $this->admin->id], $leads->pluck('created_by')->all());
    }

    public function test_possible_duplicates_are_flagged_and_require_confirmation(): void
    {
        $existing = $this->lead($this->t, ['full_name' => 'Asha Verma', 'phone_e164' => '+919876543210', 'email' => 'old@example.test']);

        $response = $this->as($this->manager)->post('/leads', $this->leadPayload($this->t, ['email' => 'new@example.test']));

        $response->assertSessionHasErrors('duplicate');
        $response->assertSessionHas('duplicates', [['visible' => true, 'id' => $existing->public_id, 'full_name' => 'Asha Verma', 'matches' => ['phone'], 'archived' => false]]);
        $this->assertCount(1, $this->leads());

        $this->as($this->manager)->post('/leads', $this->leadPayload($this->t, ['email' => 'new@example.test', 'confirm_duplicate' => true]))->assertSessionHasNoErrors();
        $this->assertCount(2, $this->leads());
    }

    public function test_duplicate_detection_matches_email_case_insensitively_and_phone_in_any_format(): void
    {
        $this->lead($this->t, ['phone_e164' => '+919000000001', 'email' => 'dup@example.test']);

        $this->as($this->manager)->post('/leads', $this->leadPayload($this->t, ['phone' => '', 'email' => 'DUP@Example.test']))->assertSessionHasErrors('duplicate');
        $this->as($this->manager)->post('/leads', $this->leadPayload($this->t, ['phone' => '090000 00001', 'email' => '']))->assertSessionHasErrors('duplicate');
    }

    public function test_a_duplicate_the_user_cannot_see_is_reported_without_revealing_it(): void
    {
        $this->lead($this->t, ['full_name' => 'Secret Lead', 'phone_e164' => '+919876543210', 'owner_id' => $this->manager->id]);

        $this->as($this->agent)->post('/leads', $this->leadPayload($this->t, ['email' => '']))->assertSessionHasErrors('duplicate');

        $this->assertSame([['visible' => false, 'matches' => ['phone']]], session('duplicates'));
        $this->assertStringNotContainsString('Secret Lead', json_encode(session('duplicates')));
    }

    public function test_the_same_phone_in_another_tenant_is_not_a_duplicate(): void
    {
        [$b] = $this->provision('Beta');
        $this->lead($b, ['phone_e164' => '+919876543210']);

        $this->as($this->manager)->post('/leads', $this->leadPayload($this->t, ['email' => '']))->assertSessionHasNoErrors();

        $this->assertCount(1, $this->leads());
    }

    // ------------------------------------------------------------------ view / edit

    public function test_lead_detail_exposes_an_explicit_allow_list_only(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);

        $this->as($this->manager)->get("/leads/{$lead->public_id}")->assertInertia(fn ($p) => $p
            ->where('lead.id', $lead->public_id)
            ->missing('lead.tenant_id')->missing('lead.owner_id')->missing('lead.submission_token')->missing('lead.deleted_at')
            ->where('lead.can.update', true)->where('lead.can.assign', true)->where('lead.can.archive', true));

        $this->as($this->agent)->get("/leads/{$lead->public_id}")->assertInertia(fn ($p) => $p
            ->where('lead.can.update', true)->where('lead.can.assign', false)->where('lead.can.archive', false)
            ->where('options.owners', []));
    }

    public function test_updating_a_lead_changes_details_but_never_owner_or_stage(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id], 'new');
        $qualified = $this->stageId($this->t, 'qualified');

        $this->as($this->manager)->patch("/leads/{$lead->public_id}", $this->leadPayload($this->t, [
            'full_name' => 'Renamed Person', 'phone' => '', 'email' => 'renamed@example.test', 'priority' => 'low',
            'owner_id' => $this->manager->id, 'pipeline_stage_id' => $qualified, 'created_by' => $this->admin->id,
        ]))->assertRedirect(route('leads.show', $lead));

        $fresh = $lead->fresh();
        $this->assertSame('Renamed Person', $fresh->full_name);
        $this->assertNull($fresh->phone_e164);
        $this->assertSame('low', $fresh->priority->value);
        $this->assertSame($this->agent->id, $fresh->owner_id, 'owner is changed only through the assign endpoint');
        $this->assertNotSame($qualified, $fresh->pipeline_stage_id, 'stage is changed only through the stage endpoint');
        $this->assertNotSame($this->admin->id, $fresh->created_by);
    }

    public function test_editing_to_another_leads_phone_warns_but_resaving_unchanged_values_does_not(): void
    {
        $a = $this->lead($this->t, ['phone_e164' => '+919000000001', 'email' => 'a@example.test']);
        $b = $this->lead($this->t, ['phone_e164' => '+919000000002', 'email' => 'b@example.test']);

        $this->as($this->manager)->patch("/leads/{$b->public_id}", $this->leadPayload($this->t, ['phone' => '9000000001', 'email' => 'b@example.test']))->assertSessionHasErrors('duplicate');
        $this->as($this->manager)->patch("/leads/{$b->public_id}", $this->leadPayload($this->t, ['phone' => '9000000002', 'email' => 'b@example.test', 'full_name' => 'Still B']))->assertSessionHasNoErrors();
        $this->assertSame('Still B', $b->fresh()->full_name);
    }

    public function test_archived_leads_cannot_be_edited(): void
    {
        $lead = $this->lead($this->t, ['archived_at' => now()]);

        $this->as($this->manager)->patch("/leads/{$lead->public_id}", $this->leadPayload($this->t))->assertForbidden();
        $this->as($this->manager)->get("/leads/{$lead->public_id}/edit")->assertForbidden();
    }

    public function test_non_ulid_ids_are_404_without_touching_the_database(): void
    {
        $this->as($this->manager)->get('/leads/not-a-lead')->assertNotFound();
        $this->as($this->manager)->get('/leads/1')->assertNotFound();
        $this->as($this->manager)->get('/leads/'.str_repeat('Z', 26))->assertNotFound();
    }

    // ------------------------------------------------------------------ archive / restore

    public function test_archiving_hides_a_lead_everywhere_but_keeps_its_history_and_is_reversible_and_audited(): void
    {
        $lead = $this->lead($this->t, ['owner_id' => $this->agent->id]);

        $this->as($this->manager)->post("/leads/{$lead->public_id}/archive")->assertRedirect(route('leads.index'));

        $fresh = $lead->fresh();
        $this->assertNotNull($fresh->archived_at);
        $this->assertSame($this->manager->id, $fresh->archived_by);
        $this->as($this->manager)->get('/leads')->assertInertia(fn ($p) => $p->where('leads.total', 0));
        $this->as($this->manager)->get('/pipeline')->assertInertia(fn ($p) => $p->where('columns.0.count', 0));
        $this->as($this->agent)->get("/leads/{$lead->public_id}")->assertNotFound();
        $this->as($this->manager)->get("/leads/{$lead->public_id}")->assertOk(); // managers can still open it

        $this->as($this->manager)->post("/leads/{$lead->public_id}/restore")->assertRedirect(route('leads.show', $lead));
        $this->assertNull($lead->fresh()->archived_at);
        $this->as($this->agent)->get("/leads/{$lead->public_id}")->assertOk();

        $actions = $this->runIn($this->t, fn () => AuditLog::query()->whereIn('action', ['lead.archived', 'lead.restored'])->orderBy('id')->pluck('action')->all());
        $this->assertSame(['lead.archived', 'lead.restored'], $actions);
    }

    public function test_archive_and_restore_are_idempotent_and_state_checked(): void
    {
        $lead = $this->lead($this->t);

        $this->as($this->manager)->post("/leads/{$lead->public_id}/restore")->assertForbidden(); // not archived
        $this->as($this->manager)->post("/leads/{$lead->public_id}/archive")->assertRedirect();
        $this->as($this->manager)->post("/leads/{$lead->public_id}/archive")->assertForbidden(); // already archived
    }

    // ------------------------------------------------------------------ list: search / filter / sort / paginate

    public function test_search_matches_name_company_email_and_phone_digits(): void
    {
        $this->lead($this->t, ['full_name' => 'Priya Nair', 'company_name' => 'Nair Textiles', 'email' => 'priya@mills.test', 'phone_e164' => '+919811122233']);
        $this->lead($this->t, ['full_name' => 'Ravi Shah', 'company_name' => 'Shah & Sons', 'email' => 'ravi@shah.test', 'phone_e164' => '+919822233344']);

        foreach (['priya' => 1, 'textiles' => 1, 'shah.test' => 1, '98111' => 1, '+91 98222' => 1, 'zzz' => 0, 'a' => 2] as $q => $total) {
            $this->as($this->manager)->get('/leads?q='.urlencode($q))->assertInertia(fn ($p) => $p->where('leads.total', $total), "q=$q");
        }
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        $this->lead($this->t, ['full_name' => '100% Legit']);
        $this->lead($this->t, ['full_name' => 'Plain Name']);

        $this->as($this->manager)->get('/leads?q='.urlencode('%'))->assertInertia(fn ($p) => $p->where('leads.total', 1));
        $this->as($this->manager)->get('/leads?q='.urlencode('_'))->assertInertia(fn ($p) => $p->where('leads.total', 0));
    }

    public function test_search_is_safe_against_sql_injection_payloads(): void
    {
        $this->lead($this->t);

        $this->as($this->manager)->get('/leads?q='.urlencode("' OR 1=1 --"))->assertOk()->assertInertia(fn ($p) => $p->where('leads.total', 0));
        $this->as($this->manager)->get('/leads?sort='.urlencode('id; DROP TABLE leads'))->assertOk()->assertInertia(fn ($p) => $p->where('filters.sort', 'created'));
        $this->assertCount(1, $this->leads());
    }

    public function test_filters_by_stage_source_owner_and_priority(): void
    {
        $this->lead($this->t, ['owner_id' => $this->agent->id, 'priority' => 'high'], 'qualified');
        $this->lead($this->t, ['owner_id' => $this->manager->id, 'priority' => 'low'], 'new');
        $this->lead($this->t, ['owner_id' => null, 'priority' => 'low'], 'new');
        $q = $this->stageId($this->t, 'qualified');

        $cases = [
            "stage=$q" => 1, 'priority=low' => 2, 'priority=high' => 1, 'priority=bogus' => 3,
            'owner=unassigned' => 1, 'owner='.$this->agent->id => 1, 'owner=me' => 1, 'stage=999999' => 0,
            'source='.$this->sourceId($this->t) => 3, 'source='.$this->sourceId($this->t, 'website') => 0,
        ];
        foreach ($cases as $query => $total) {
            $this->as($this->manager)->get("/leads?$query")->assertInertia(fn ($p) => $p->where('leads.total', $total), $query);
        }
    }

    public function test_agents_cannot_widen_their_scope_with_the_owner_filter(): void
    {
        $other = $this->member($this->t, RoleName::SalesAgent);
        $this->lead($this->t, ['owner_id' => $this->agent->id]);
        $this->lead($this->t, ['owner_id' => $other->id]);
        $this->lead($this->t, ['owner_id' => null]);

        foreach (['owner='.$other->id, 'owner=unassigned', 'owner=me', ''] as $query) {
            $this->as($this->agent)->get("/leads?$query")->assertInertia(fn ($p) => $p->where('leads.total', 1)->where('filters.owner', null), $query);
        }
    }

    public function test_sorting_is_whitelisted_stable_and_puts_nulls_last(): void
    {
        $this->lead($this->t, ['full_name' => 'Bravo', 'estimated_value' => 500]);
        $this->lead($this->t, ['full_name' => 'Alpha', 'estimated_value' => null]);
        $this->lead($this->t, ['full_name' => 'Charlie', 'estimated_value' => 100]);

        $names = fn (string $query) => collect($this->as($this->manager)->get("/leads?$query")->viewData('page')['props']['leads']['data'])->pluck('full_name')->all();

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $names('sort=name&dir=asc'));
        $this->assertSame(['Charlie', 'Bravo', 'Alpha'], $names('sort=name&dir=desc'));
        $this->assertSame(['Charlie', 'Bravo', 'Alpha'], $names('sort=value&dir=asc'), 'NULL values sort last');
        $this->assertSame(['Bravo', 'Charlie', 'Alpha'], $names('sort=value&dir=desc'), 'NULL values sort last');
    }

    public function test_pagination_pages_are_disjoint_complete_and_keep_the_query_string(): void
    {
        config(['crm.leads_per_page' => 5]);
        foreach (range(1, 12) as $i) {
            $this->lead($this->t, ['full_name' => sprintf('Lead %02d', $i), 'priority' => 'high']);
        }

        $page = fn (int $n) => $this->as($this->manager)->get("/leads?priority=high&sort=name&dir=asc&page=$n")->viewData('page')['props']['leads'];

        $p1 = $page(1);
        $this->assertSame(12, $p1['total']);
        $this->assertSame(3, $p1['last_page']);
        $this->assertStringContainsString('priority=high', $p1['next_page_url']);
        $this->assertStringContainsString('sort=name', $p1['next_page_url']);

        $seen = collect([1, 2, 3])->flatMap(fn ($n) => collect($page($n)['data'])->pluck('full_name'))->all();
        $this->assertSame(array_map(fn ($i) => sprintf('Lead %02d', $i), range(1, 12)), $seen);
        $this->assertCount(2, $page(3)['data']);
    }

    public function test_list_queries_are_not_n_plus_one(): void
    {
        foreach (range(1, 15) as $i) {
            $this->lead($this->t, ['owner_id' => $this->agent->id]);
        }

        DB::enableQueryLog();
        $this->as($this->manager)->get('/leads')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(40, $count, "list page ran $count queries for 15 leads");
    }
}
