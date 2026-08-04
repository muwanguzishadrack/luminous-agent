<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Where a signed-in user belongs.
 *
 * One place, because two very different callers need the same answer: Fortify's
 * auth responses, and Laravel's RedirectIfAuthenticated guest middleware. The
 * latter otherwise falls back to `route('dashboard')`, which throws once
 * dashboard requires a {team} segment and the user has no team to fill it
 * with (D-020) — a 500 on the exact path an invitee takes.
 */
class HomeRedirect
{
    public static function for(?User $user, string $redirect = '/dashboard'): string
    {
        $team = $user?->team;

        if ($team === null) {
            // Someone invited to a team they have not joined yet lands on the
            // invitation rather than their profile — otherwise the code in
            // their email is dropped at login and there is no way back to it.
            if ($user !== null && $user->pendingInvitations()->exists()) {
                return route('invitations.index', absolute: false);
            }

            return route('profile.edit', absolute: false);
        }

        URL::defaults(['team' => $team->slug]);

        return "/{$team->slug}{$redirect}";
    }
}
