<?php

namespace App\Http\Controllers;

use App\Models\Conversion;
use App\Models\CtwaReferral;
use Inertia\Inertia;
use Inertia\Response;

class GrowthController extends Controller
{
    /**
     * Display the growth workspace (CTWA, links and ROAS).
     */
    public function __invoke(): Response
    {
        return Inertia::render('growth/index', [
            'referralCount' => CtwaReferral::count(),
            'conversionCount' => Conversion::count(),
        ]);
    }
}
