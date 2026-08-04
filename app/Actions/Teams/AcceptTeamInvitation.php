<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Facades\Teams;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Joins a user to the inviting team. A user belongs to at most one team
 * (D-020), so accepting is only ever possible for someone who has none —
 * the check here mirrors the unique index on team_user.user_id.
 */
class AcceptTeamInvitation
{
    public function handle(User $user, TeamInvitation $invitation): Team
    {
        if ($user->belongsToAnyTeam()) {
            throw new RuntimeException('This user already belongs to a team and cannot join another (D-020).');
        }

        return DB::transaction(function () use ($user, $invitation) {
            $team = $invitation->team;

            // The invitation's team is not the session's context yet — RLS on
            // team_user requires it before the membership insert.
            Teams::initialize($team);

            $team->memberships()->create([
                'user_id' => $user->id,
                'role' => $invitation->role,
                'status' => 'active',
                'invited_by' => $invitation->invited_by,
                'invited_at' => $invitation->created_at,
                'joined_at' => now(),
            ]);

            $invitation->fill(['accepted_at' => now()])->save();

            $user->setRelation('team', $team);

            return $team;
        });
    }
}
