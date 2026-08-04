<?php

namespace App\Concerns;

use App\Data\TeamPermissions;
use App\Data\UserTeam;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * A user belongs to **at most one team** (D-020). The single `team_user` row
 * is the team context — there is no pointer on the user that can disagree
 * with it, and therefore nothing to switch.
 */
trait HasTeams
{
    /**
     * Get the user's single team membership.
     *
     * @return HasOne<Membership, $this>
     */
    public function membership(): HasOne
    {
        return $this->hasOne(Membership::class, 'user_id');
    }

    /**
     * Get the user's single team.
     *
     * @return HasOneThrough<Team, Membership, $this>
     */
    public function team(): HasOneThrough
    {
        return $this->hasOneThrough(
            Team::class,
            Membership::class,
            'user_id',
            'id',
            'id',
            'team_id',
        );
    }

    /**
     * Unanswered, unexpired invitations addressed to this user's email.
     *
     * Deliberately not a team-scoped read: an invitation is by definition an
     * offer from a team the user does not belong to yet, so `team_invitations`
     * carries neither the TeamScope nor an RLS policy. The email match is the
     * authorization boundary, and it is re-checked by ValidTeamInvitation
     * before anything is accepted.
     *
     * @return Builder<TeamInvitation>
     */
    public function pendingInvitations(): Builder
    {
        return TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [strtolower($this->email)])
            ->whereNull('accepted_at')
            ->where(fn (Builder $query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest();
    }

    /**
     * Determine if the user belongs to the given team.
     */
    public function belongsToTeam(Team $team): bool
    {
        return $this->membership()->where('team_id', $team->id)->exists();
    }

    /**
     * Determine if the user already belongs to a team.
     */
    public function belongsToAnyTeam(): bool
    {
        return $this->membership()->exists();
    }

    /**
     * Determine if the user is the owner of the given team.
     */
    public function ownsTeam(Team $team): bool
    {
        return $this->teamRole($team) === TeamRole::Owner;
    }

    /**
     * Get the user's role on the given team.
     */
    public function teamRole(Team $team): ?TeamRole
    {
        return $this->membership()
            ->where('team_id', $team->id)
            ->first()
            ?->role;
    }

    /**
     * Get the user's team as a UserTeam object.
     */
    public function toUserTeam(Team $team): UserTeam
    {
        $role = $this->teamRole($team);

        return new UserTeam(
            id: $team->id,
            name: $team->name,
            slug: $team->slug,
            role: $role?->value,
            roleLabel: $role?->label(),
        );
    }

    /**
     * Get the standard permissions for a team as a TeamPermissions object.
     */
    public function toTeamPermissions(Team $team): TeamPermissions
    {
        $role = $this->teamRole($team);

        return new TeamPermissions(
            canUpdateTeam: $role?->hasPermission(TeamPermission::UpdateTeam) ?? false,
            canDeleteTeam: $role?->hasPermission(TeamPermission::DeleteTeam) ?? false,
            canAddMember: $role?->hasPermission(TeamPermission::AddMember) ?? false,
            canUpdateMember: $role?->hasPermission(TeamPermission::UpdateMember) ?? false,
            canRemoveMember: $role?->hasPermission(TeamPermission::RemoveMember) ?? false,
            canCreateInvitation: $role?->hasPermission(TeamPermission::CreateInvitation) ?? false,
            canCancelInvitation: $role?->hasPermission(TeamPermission::CancelInvitation) ?? false,
        );
    }

    /**
     * Determine if the user has the given permission on the team.
     */
    public function hasTeamPermission(Team $team, TeamPermission $permission): bool
    {
        return $this->teamRole($team)?->hasPermission($permission) ?? false;
    }
}
