<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Label;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    /**
     * Display the contact directory.
     */
    public function __invoke(): Response
    {
        return Inertia::render('contacts/index', [
            'contactCount' => Contact::count(),
            'labelCount' => Label::count(),
        ]);
    }
}
