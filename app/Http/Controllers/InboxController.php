<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    /**
     * Display the team inbox.
     */
    public function __invoke(): Response
    {
        return Inertia::render('inbox/index', [
            'conversationCount' => Conversation::count(),
            'messageCount' => Message::count(),
        ]);
    }
}
