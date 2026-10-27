<?php

namespace Tests\Feature\Tenancy;

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Setting;
use App\Support\Facades\Tenancy;
use App\Tenancy\BelongsToTenant;
use App\Tenancy\Exceptions\TenantContextMissing;
use App\Tenancy\Exceptions\TenantMismatch;
use Tests\TestCase;

class TenantScopeTest extends TestCase
{
    public function test_queries_only_return_current_tenant_rows(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');

        $this->runIn($a, fn () => Lead::factory()->count(2)->create());
        $this->runIn($b, fn () => Lead::factory()->count(3)->create());

        $this->assertSame(2, $this->runIn($a, fn () => Lead::query()->count()));
        $this->assertSame(3, $this->runIn($b, fn () => Lead::query()->count()));
        $this->assertSame(7, $this->runIn($a, fn () => LeadSource::query()->count()));
    }

    public function test_tenant_owned_queries_fail_closed_without_a_tenant(): void
    {
        $this->provision('Alpha');

        $this->expectException(TenantContextMissing::class);
        Lead::query()->count();
    }

    public function test_creating_without_tenant_context_fails(): void
    {
        $this->expectException(TenantContextMissing::class);
        Setting::query()->create(['key' => 'x', 'value' => 1]);
    }

    public function test_tenant_id_is_filled_from_context(): void
    {
        [$a] = $this->provision('Alpha');

        $lead = $this->runIn($a, fn () => Lead::factory()->create());

        $this->assertSame($a->id, $lead->tenant_id);
    }

    public function test_forging_another_tenants_id_is_refused(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');

        $this->expectException(TenantMismatch::class);
        $this->runIn($a, fn () => Lead::factory()->create(['tenant_id' => $b->id]));
    }

    public function test_tenant_id_is_immutable(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');
        $lead = $this->runIn($a, fn () => Lead::factory()->create());

        $this->expectException(TenantMismatch::class);
        $this->runIn($a, function () use ($lead, $b) {
            $lead->tenant_id = $b->id;
            $lead->save();
        });
    }

    public function test_other_tenants_record_cannot_be_found_or_modified_by_id(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');
        $leadB = $this->runIn($b, fn () => Lead::factory()->create());

        $this->assertNull($this->runIn($a, fn () => Lead::query()->find($leadB->id)));
        $this->assertSame(0, $this->runIn($a, fn () => Lead::query()->whereKey($leadB->id)->update(['full_name' => 'pwned'])));
        $this->assertNotSame('pwned', $this->runIn($b, fn () => Lead::query()->find($leadB->id))->full_name);
    }

    public function test_run_as_restores_previous_context_even_on_exception(): void
    {
        [$a] = $this->provision('Alpha');
        [$b] = $this->provision('Beta');

        Tenancy::set($a);
        try {
            Tenancy::runAs($b, fn () => throw new \RuntimeException('x'));
        } catch (\RuntimeException) {
        }

        $this->assertSame($a->id, Tenancy::id());
    }

    public function test_every_model_with_a_tenant_id_column_is_tenant_scoped(): void
    {
        $missing = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            $model = new $class;

            if ($model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), 'tenant_id')
                && ! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing, 'Models with tenant_id must use BelongsToTenant.');
    }

    public function test_application_code_does_not_bypass_global_scopes_directly(): void
    {
        $offenders = [];

        foreach ((new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()))) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                && preg_match('/withoutGlobalScopes?\(/', file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders, 'Use Tenancy::withoutScope() so bypasses stay explicit and greppable.');
    }

    /**
     * Tripwire for surfaces that exist today only as design rules (plan §3A.8): shared cache keys, file storage
     * and exports are not tenant-scoped automatically. If this fails, key/path them by tenant (t:{id}:..., tenants/{public_id}/...)
     * and authorize downloads by tenant ownership, then add the file to the reviewed list below.
     */
    public function test_no_unreviewed_cache_storage_or_export_code_exists(): void
    {
        $reviewed = []; // relative paths that have been reviewed for tenant isolation
        $offenders = [];

        foreach ((new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()))) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace(base_path().'/', '', $file->getPathname());
            if (preg_match('/\b(Cache::|cache\(|Storage::|streamDownload|->download\(|fputcsv|Excel::|Mail::to|->queue\()/', file_get_contents($file->getPathname())) && ! in_array($relative, $reviewed, true)) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, 'New cache/storage/export/queued-mail code needs a tenant-isolation review.');
    }
}
