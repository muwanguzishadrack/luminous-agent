<?php

namespace App\Support;

use App\Exceptions\MissingTeamContext;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Holds the active team context for the current request / job and mirrors it
 * into the Postgres session (`app.team_id`, `app.user_id`) so Row Level
 * Security enforces the same boundary at the database layer
 * (docs/05-security-multitenancy.md §1).
 *
 * Registered as a scoped singleton — Octane resets it per request. Queued jobs
 * must re-establish context in handle() (docs/05 §1, "dangerous paths").
 */
class TeamManager
{
    private ?Team $team = null;

    /**
     * Establish team context for this request/job.
     */
    public function initialize(Team $team): void
    {
        $this->team = $team;

        DB::statement("SELECT set_config('app.team_id', ?, false)", [(string) $team->id]);
    }

    /**
     * Identify the authenticated user to the database session. Required by the
     * user-aware RLS policy on team_user (membership listing pre-context).
     */
    public function actingAs(?User $user): void
    {
        DB::statement("SELECT set_config('app.user_id', ?, false)", [(string) $user?->id]);
    }

    /**
     * Clear all context, including the database session variables. Both are
     * reset: a stale app.user_id would let the user-aware team_user policy
     * admit somebody else's membership on a pooled connection.
     */
    public function forget(): void
    {
        $this->team = null;

        DB::statement("SELECT set_config('app.team_id', '', false)");
        DB::statement("SELECT set_config('app.user_id', '', false)");
    }

    public function current(): ?Team
    {
        return $this->team;
    }

    public function currentId(): ?string
    {
        return $this->team?->id;
    }

    /**
     * @throws MissingTeamContext when no team context is established.
     */
    public function currentOrFail(): Team
    {
        return $this->team ?? throw new MissingTeamContext;
    }

    /**
     * @throws MissingTeamContext when no team context is established —
     *                            there is deliberately no silent default (docs/05 §1 layer 1).
     */
    public function currentIdOrFail(): string
    {
        return $this->currentId() ?? throw new MissingTeamContext;
    }

    public function initialized(): bool
    {
        return $this->team !== null;
    }
}
