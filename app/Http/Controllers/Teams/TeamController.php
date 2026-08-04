<?php

namespace App\Http\Controllers\Teams;

use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\DeleteTeamRequest;
use App\Http\Requests\Teams\SaveTeamRequest;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Support\Facades\Teams;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The single team-settings screen (D-020). There is no index — a user has at
 * most one team, so there is nothing to list and nothing to switch between.
 */
class TeamController extends Controller
{
    /**
     * Show the team settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $team = Teams::currentOrFail();

        return Inertia::render('settings/team', [
            'members' => $team->members()->get()->map(function (User $member) {
                /** @var Membership $membership */
                $membership = $member->getRelation('pivot');

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar' => $member->avatar ?? null,
                    'role' => $membership->role->value,
                    'role_label' => $membership->role->label(),
                ];
            }),
            'invitations' => $team->invitations()
                ->whereNull('accepted_at')
                ->get()
                ->map(fn ($invitation) => [
                    'code' => $invitation->code,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'role_label' => $invitation->role->label(),
                    'created_at' => $invitation->created_at->toISOString(),
                ]),
            'permissions' => $user->toTeamPermissions($team),
            'availableRoles' => TeamRole::assignable(),
        ]);
    }

    /**
     * Update the team.
     */
    public function update(SaveTeamRequest $request): RedirectResponse
    {
        $team = Teams::currentOrFail();

        Gate::authorize('update', $team);

        DB::transaction(function () use ($request, $team) {
            Team::whereKey($team->id)
                ->lockForUpdate()
                ->firstOrFail()
                ->update(['name' => $request->validated('name')]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team updated.')]);

        return to_route('team.edit');
    }

    /**
     * Delete the team. Every member is left without one, which is the honest
     * consequence of one team per user (D-020).
     */
    public function destroy(DeleteTeamRequest $request): RedirectResponse
    {
        $team = Teams::currentOrFail();

        DB::transaction(function () use ($team) {
            $team->invitations()->delete();
            $team->memberships()->delete();
            $team->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team deleted.')]);

        return to_route('profile.edit');
    }
}
