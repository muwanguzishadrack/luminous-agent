<?php

namespace App\Http\Controllers;

use App\Data\PendingInvitation;
use App\Models\PhoneNumber;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboard', [
            // Anyone reaching the dashboard already has a team, so these can
            // only be declined — one team per user (D-020).
            'pendingInvitations' => $request->user()
                ->pendingInvitations()
                ->get()
                ->map(PendingInvitation::fromModel(...))
                ->values(),
            // Team-scoped automatically; drives the Connect WhatsApp
            // callout until the first number lands (docs/m0 §7).
            'hasWhatsAppNumbers' => PhoneNumber::query()->exists(),
        ]);
    }
}
