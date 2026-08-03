<?php

namespace App\Http\Controllers;

use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use Inertia\Inertia;
use Inertia\Response;

class SequenceController extends Controller
{
    /**
     * Display the message sequences.
     */
    public function __invoke(): Response
    {
        return Inertia::render('sequences/index', [
            'sequenceCount' => Sequence::count(),
            'enrollmentCount' => SequenceEnrollment::count(),
        ]);
    }
}
