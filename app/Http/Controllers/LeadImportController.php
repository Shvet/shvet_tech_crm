<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Services\Leads\LeadCsvImporter;
use App\Services\Leads\LeadOptions;
use App\Support\AuditLogger;
use App\Support\Facades\Tenancy;
use App\Support\TenantRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/** CSV import of leads (leads.import). Runs synchronously; files are capped by size and row count. */
class LeadImportController extends Controller
{
    public function create(Request $request): Response
    {
        abort_unless($request->user()->checkPermissionTo(Permission::LeadsImport->value), 403);

        return Inertia::render('Leads/Import', [
            'sources' => LeadOptions::sources(),
            'result' => $request->session()->get('import_result'),
            'limits' => ['rows' => (int) config('crm.import_max_rows'), 'kb' => (int) config('crm.import_max_kb')],
        ]);
    }

    public function store(Request $request, LeadCsvImporter $importer): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->checkPermissionTo(Permission::LeadsImport->value), 403);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.(int) config('crm.import_max_kb')],
            'lead_source_id' => ['required', 'integer', TenantRules::exists('lead_sources', 'id', activeOnly: true)],
            'allow_duplicates' => ['sometimes', 'boolean'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        // One import at a time per user: a double-clicked button must not import the same file twice in parallel.
        $lock = Cache::lock('t:'.Tenancy::id().':lead-import:'.$user->getKey(), 300);
        if (! $lock->get()) {
            return back()->withErrors(['file' => 'An import is already running. Wait for it to finish.']);
        }

        try {
            $result = $importer->run(
                $user,
                $data['file']->getRealPath(),
                (int) $data['lead_source_id'],
                allowDuplicates: $request->boolean('allow_duplicates'),
                dryRun: $request->boolean('dry_run'),
            );
        } finally {
            $lock->release();
        }

        if (! $result['dry_run']) {
            AuditLogger::record('leads.imported', null, [
                'created' => $result['created'], 'duplicates' => $result['duplicates'], 'failed' => $result['failed'],
            ]);
        }

        return redirect()->route('leads.import')->with('import_result', $result);
    }
}
