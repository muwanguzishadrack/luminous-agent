<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetTeamUrlDefaults
{
    /**
     * Set the default `{team}` parameter from the user's single team, so
     * team-prefixed routes can be generated without naming it (D-020).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($team = $request->user()?->team) {
            URL::defaults(['team' => $team->slug]);
        }

        return $next($request);
    }
}
