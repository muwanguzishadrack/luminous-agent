<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Facades\Teams;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates the user's one and only team, with them as owner. A team is created
 * exactly once, at registration (D-020) — a second one is refused here as
 * well as by the unique index on team_user.user_id.
 */
class CreateTeam
{
    public function handle(User $user, string $name): Team
    {
        if ($user->belongsToAnyTeam()) {
            throw new RuntimeException(
                'This user already belongs to a team; a person running two businesses needs two logins (D-020).',
            );
        }

        return DB::transaction(function () use ($user, $name) {
            $team = Team::create(['name' => $name]);

            // The team did not exist a moment ago — establish its context
            // before the first team-scoped write (RLS enforces this).
            Teams::initialize($team);

            $team->memberships()->create([
                'user_id' => $user->id,
                'role' => TeamRole::Owner,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            $user->setRelation('team', $team);

            return $team;
        });
    }
}
