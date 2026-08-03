<?php

namespace App\Http\Controllers;

use App\Models\MbaAgent;
use App\Models\MbaKnowledgeSource;
use Inertia\Inertia;
use Inertia\Response;

class AgentController extends Controller
{
    /**
     * Display the AI agent workspace.
     */
    public function __invoke(): Response
    {
        return Inertia::render('agent/index', [
            'agentCount' => MbaAgent::count(),
            'knowledgeSourceCount' => MbaKnowledgeSource::count(),
        ]);
    }
}
