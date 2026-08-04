<?php

namespace App\Http\Responses\Concerns;

use App\Support\HomeRedirect;
use Illuminate\Http\Request;

/**
 * Auth responses land inside the user's single team (D-020). A user with no
 * team — one who was removed from theirs, or who has been invited but not yet
 * joined — has nowhere team-prefixed to go, so HomeRedirect decides for them.
 */
trait RedirectsToTeam
{
    protected function redirectPathForTeam(Request $request, string $redirect): string
    {
        return HomeRedirect::for($request->user(), $redirect);
    }
}
