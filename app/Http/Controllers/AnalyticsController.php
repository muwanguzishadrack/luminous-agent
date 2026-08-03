<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    /**
     * Display the analytics workspace.
     */
    public function __invoke(): Response
    {
        return Inertia::render('analytics/index');
    }
}
