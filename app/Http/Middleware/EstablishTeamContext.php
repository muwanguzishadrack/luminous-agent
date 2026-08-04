<?php

namespace App\Http\Middleware;

use App\Support\Facades\Teams;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifies the authenticated user to the database session, then establishes
 * the user's single team as the request context (D-020). The membership row
 * *is* the context: `app.user_id` is set first so the user-aware RLS policy
 * on `team_user` admits the read that resolves it (docs/05 §1 layer 2).
 */
class EstablishTeamContext
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        Teams::actingAs($user);

        if ($team = $user?->team) {
            Teams::initialize($team);
        }

        return $next($request);
    }
}
