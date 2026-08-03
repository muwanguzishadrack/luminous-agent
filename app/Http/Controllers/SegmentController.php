<?php

namespace App\Http\Controllers;

use App\Models\Segment;
use Inertia\Inertia;
use Inertia\Response;

class SegmentController extends Controller
{
    /**
     * Display the audience segments.
     */
    public function __invoke(): Response
    {
        return Inertia::render('segments/index', [
            'segmentCount' => Segment::count(),
        ]);
    }
}
