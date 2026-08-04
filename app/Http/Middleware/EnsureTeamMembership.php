<?php

namespace App\Http\Middleware;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Facades\Teams;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confirms the request runs as a team the user actually belongs to. Routes
 * carrying a `{team}` slug are checked against the user's single membership;
 * routes without one resolve the team from that membership (D-020).
 */
class EnsureTeamMembership
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $minimumRole = null): Response
    {
        $user = $request->user();

        // A route that names a team must be met on that team's terms: an
        // unknown slug is a refusal, never a silent fallback to the user's own.
        $team = $request->route('team') === null
            ? $user?->team
            : $this->routeTeam($request);

        abort_if(! $user || ! $team || ! $user->belongsToTeam($team), 403);

        $this->ensureTeamMemberHasRequiredRole($user, $team, $minimumRole);

        Teams::initialize($team);

        return $next($request);
    }

    /**
     * Ensure the given user has at least the given role, if applicable.
     */
    protected function ensureTeamMemberHasRequiredRole(User $user, Team $team, ?string $minimumRole): void
    {
        if ($minimumRole === null) {
            return;
        }

        $role = $user->teamRole($team);

        $requiredRole = TeamRole::tryFrom($minimumRole);

        abort_if(
            $requiredRole === null ||
            $role === null ||
            ! $role->isAtLeast($requiredRole),
            403,
        );
    }

    /**
     * Get the team named by the route, when the route names one.
     */
    protected function routeTeam(Request $request): ?Team
    {
        $team = $request->route('team');

        if (is_string($team)) {
            $team = Team::where('slug', $team)->first();
        }

        return $team instanceof Team ? $team : null;
    }
}
