<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Support\AuditLogger;
use App\Support\Facades\Tenancy;
use App\Tenancy\Exceptions\TenantMismatch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogIntegrityTest extends TestCase
{
    private function someEntry(): AuditLog
    {
        [$a] = $this->provision('Alpha');

        return $this->runIn($a, fn () => AuditLog::query()->firstOrFail());
    }

    public function test_model_level_update_and_delete_are_refused(): void
    {
        $entry = $this->someEntry();

        $this->expectException(\LogicException::class);
        Tenancy::withoutScope(fn () => $entry->update(['action' => 'tampered']));
    }

    public function test_model_level_delete_is_refused(): void
    {
        $entry = $this->someEntry();

        $this->expectException(\LogicException::class);
        Tenancy::withoutScope(fn () => $entry->delete());
    }

    public function test_query_builder_bulk_update_is_rejected_by_the_database(): void
    {
        $this->someEntry();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        Tenancy::withoutScope(fn () => AuditLog::query()->update(['action' => 'tampered']));
    }

    public function test_query_builder_bulk_delete_is_rejected_by_the_database(): void
    {
        $this->someEntry();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        Tenancy::withoutScope(fn () => AuditLog::query()->delete());
    }

    public function test_raw_sql_update_and_delete_are_rejected_by_the_database(): void
    {
        $this->someEntry();

        foreach (['UPDATE audit_logs SET action = "x"', 'DELETE FROM audit_logs'] as $sql) {
            try {
                DB::statement($sql);
                $this->fail("Expected the database to reject: $sql");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        $this->assertGreaterThan(0, DB::table('audit_logs')->count());
    }

    public function test_truncate_is_not_available_inside_the_application_user_flow_but_inserts_still_work(): void
    {
        [$a] = $this->provision('Alpha');
        $before = DB::table('audit_logs')->count();

        $this->runIn($a, fn () => AuditLogger::record('test.event', null, ['access_token' => 'EAAsecret', 'note' => 'ok']));

        $this->assertSame($before + 1, DB::table('audit_logs')->count());
        $row = DB::table('audit_logs')->where('action', 'test.event')->first();
        $this->assertStringNotContainsString('EAAsecret', $row->metadata);
    }

    public function test_deleting_a_user_who_has_audit_history_is_blocked_not_nulled(): void
    {
        [$a, $admin] = $this->provision('Alpha');
        $this->actingInTenant($admin, $a)->post('/team/members', ['name' => 'x', 'email' => 'x@example.test', 'role' => 'Sales Agent']);
        $this->assertGreaterThan(0, DB::table('audit_logs')->where('user_id', $admin->id)->count());

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $admin->id)->delete();
    }

    public function test_tenant_erasure_still_works_via_fk_cascade(): void
    {
        [$a] = $this->provision('Alpha');
        $this->assertGreaterThan(0, DB::table('audit_logs')->where('tenant_id', $a->id)->count());

        DB::table('tenants')->where('id', $a->id)->delete();

        $this->assertSame(0, DB::table('audit_logs')->where('tenant_id', $a->id)->count());
    }

    public function test_a_tenant_can_neither_read_nor_write_other_tenants_audit_entries(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');

        $this->assertSame(0, $this->runIn($a, fn () => AuditLog::query()->where('tenant_id', $b->id)->count()));
        $this->expectException(TenantMismatch::class);
        $this->runIn($a, fn () => AuditLog::query()->create(['tenant_id' => $b->id, 'action' => 'forged']));
    }
}
