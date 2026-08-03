<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    /**
     * Display the broadcast campaigns.
     */
    public function __invoke(): Response
    {
        return Inertia::render('campaigns/index', [
            'campaignCount' => Campaign::count(),
            'recipientCount' => CampaignRecipient::count(),
        ]);
    }
}
