<?php

namespace App\Http\Controllers\Teams;

use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\UpdateTeamMemberRequest;
use App\Models\User;
use App\Support\Facades\Teams;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TeamMemberController extends Controller
{
    /**
     * Update the specified team member's role.
     */
    public function update(UpdateTeamMemberRequest $request, User $user): RedirectResponse
    {
        $team = Teams::currentOrFail();

        Gate::authorize('updateMember', $team);

        $team->memberships()
            ->where('user_id', $user->id)
            ->firstOrFail()
            ->update(['role' => TeamRole::from($request->validated('role'))]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated.')]);

        return to_route('team.edit');
    }

    /**
     * Remove the specified team member. They are left without a team — one
     * team per user means there is nowhere to fall back to (D-020).
     */
    public function destroy(User $user): RedirectResponse
    {
        $team = Teams::currentOrFail();

        Gate::authorize('removeMember', $team);

        abort_if($team->owner()?->is($user), 403, __('The team owner cannot be removed.'));

        $team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('team.edit');
    }
}
