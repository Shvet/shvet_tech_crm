<?php

namespace App\Http\Controllers\Legal;

use App\Http\Controllers\Controller;
use App\Models\MetaDataDeletionRequest;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public policy pages. Plain server-rendered HTML (not Inertia) so Meta's reviewers and crawlers see the full text
 * without running JavaScript.
 */
class LegalPageController extends Controller
{
    public function privacy(): View
    {
        return view('legal.privacy', $this->shared());
    }

    public function terms(): View
    {
        return view('legal.terms', $this->shared());
    }

    public function dataDeletion(): View
    {
        return view('legal.data-deletion', $this->shared());
    }

    public function deletionStatus(Request $request): View
    {
        $code = strtoupper(trim((string) $request->query('code', '')));
        $record = preg_match('/^[A-Z0-9]{8,32}$/', $code) === 1
            ? MetaDataDeletionRequest::query()->where('confirmation_code', $code)->first()
            : null;

        return view('legal.deletion-status', $this->shared() + ['code' => $code, 'record' => $record]);
    }

    /** @return array<string, mixed> */
    private function shared(): array
    {
        return [
            'company' => (string) config('legal.company_name'),
            'contactEmail' => config('legal.contact_email'),
            'address' => config('legal.address'),
            'effectiveDate' => (string) config('legal.effective_date'),
            'governingLaw' => (string) config('legal.governing_law'),
            'deletionDays' => (int) config('legal.deletion_days'),
            'appName' => (string) config('app.name'),
        ];
    }
}
