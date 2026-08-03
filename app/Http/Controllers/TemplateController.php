<?php

namespace App\Http\Controllers;

use App\Models\Template;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    /**
     * Display the message template library.
     */
    public function __invoke(): Response
    {
        return Inertia::render('templates/index', [
            'templateCount' => Template::count(),
        ]);
    }
}
