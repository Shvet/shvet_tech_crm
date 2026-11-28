<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Services\Reports\SalesReport;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Sales reports (reports.view). Dates are local to the workspace; the range is capped at two years. */
class ReportController extends Controller
{
    public const PRESETS = ['7' => 7, '30' => 30, '90' => 90, '365' => 365];

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->checkPermissionTo(Permission::ReportsView->value), 403);

        $today = TenantTime::now()->startOfDay();
        $preset = array_key_exists((string) $request->query('range'), self::PRESETS) ? (string) $request->query('range') : null;
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));

        if ($preset !== null || $from === null || $to === null) {
            $preset ??= '30';
            $to = $today;
            $from = $today->subDays(self::PRESETS[$preset] - 1);
        } else {
            $preset = 'custom';
            if ($from->greaterThan($to)) {
                [$from, $to] = [$to, $from];
            }
            if ($from->diffInDays($to) > 731) {
                $from = $to->subDays(731);
            }
        }

        return Inertia::render('Reports/Index', [
            'report' => SalesReport::for($user, $from, $to),
            'preset' => $preset,
        ]);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, TenantTime::timezone()) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
