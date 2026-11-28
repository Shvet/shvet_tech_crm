<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Lead;
use App\Services\Leads\LeadSearch;
use App\Support\AuditLogger;
use App\Support\CsvCell;
use App\Support\Facades\Tenancy;
use App\Support\TenantTime;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export of the leads the user can see, with the same filters and order as the list. Requires leads.export.
 * The header row matches what the importer reads, so an export can be edited and imported elsewhere.
 */
class LeadExportController extends Controller
{
    public const HEADERS = [
        'name', 'company', 'email', 'phone', 'stage', 'source', 'owner_email', 'owner', 'priority', 'value',
        'created_at', 'last_contacted_at', 'archived_at', 'id',
    ];

    public function __invoke(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->checkPermissionTo(Permission::LeadsExport->value), 403);

        $filters = LeadSearch::filters($request, $user);
        $max = (int) config('crm.export_max_rows', 50000);
        $query = LeadSearch::query($filters, $user)
            ->with(['stage:id,name', 'source:id,name', 'owner:id,name,email'])
            ->limit($max);

        $total = (clone $query)->toBase()->getCountForPagination();
        AuditLogger::record('leads.exported', null, ['rows' => min($total, $max), 'filters' => array_filter($filters)]);

        $timezone = TenantTime::timezone();
        $date = fn ($value) => $value ? $value->copy()->setTimezone($timezone)->format('Y-m-d H:i') : '';
        $tenant = Tenancy::currentOrFail();
        $filename = 'leads-'.$tenant->slug.'-'.now($timezone)->format('Ymd-His').'.csv';

        // The body is streamed after the tenant middleware has returned, so the rows are read inside an explicit
        // tenant context (the scope fails closed without one).
        return response()->streamDownload(fn () => Tenancy::runAs($tenant, function () use ($query, $date) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so spreadsheet apps detect the encoding
            fputcsv($out, self::HEADERS, escape: '');

            /** @var Lead $lead */
            foreach ($query->lazy(500) as $lead) {
                fputcsv($out, array_map([CsvCell::class, 'safe'], [
                    $lead->full_name,
                    $lead->company_name,
                    $lead->email,
                    $lead->phone_e164,
                    $lead->stage?->name,
                    $lead->source?->name,
                    $lead->owner?->email,
                    $lead->owner?->name,
                    $lead->priority->value,
                    $lead->estimated_value,
                    $date($lead->created_at),
                    $date($lead->last_contacted_at),
                    $date($lead->archived_at),
                    $lead->public_id,
                ]), escape: '');
            }

            fclose($out);
        }), $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
