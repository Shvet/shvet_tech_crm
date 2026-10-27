<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** Phase 1 shell: confirms tenant + role context. Metrics arrive with the lead module. */
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard');
    }
}
