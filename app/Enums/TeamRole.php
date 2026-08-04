<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The five CRM roles (docs/02-data-model.md §1, docs/modules/m0-onboarding.md §6).
 */
#[TypeScript]
enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Supervisor = 'supervisor';
    case Agent = 'agent';
    case Viewer = 'viewer';

    /**
     * Get the display label for the role.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Get all the permissions for this role.
     *
     * @return array<TeamPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => TeamPermission::cases(),
            self::Admin => [
                TeamPermission::UpdateTeam,
                TeamPermission::CreateInvitation,
                TeamPermission::CancelInvitation,
                TeamPermission::ManageWhatsApp,
            ],
            self::Supervisor => [
                TeamPermission::CreateInvitation,
                TeamPermission::CancelInvitation,
            ],
            self::Agent, self::Viewer => [],
        };
    }

    /**
     * Determine if the role has the given permission.
     */
    public function hasPermission(TeamPermission $permission): bool
    {
        return in_array($permission, $this->permissions());
    }

    /**
     * Get the hierarchy level for this role.
     * Higher numbers indicate higher privileges.
     */
    public function level(): int
    {
        return match ($this) {
            self::Owner => 5,
            self::Admin => 4,
            self::Supervisor => 3,
            self::Agent => 2,
            self::Viewer => 1,
        };
    }

    /**
     * Check if this role is at least as privileged as another role.
     */
    public function isAtLeast(TeamRole $role): bool
    {
        return $this->level() >= $role->level();
    }

    /**
     * Get the roles that can be assigned to team members (excludes Owner).
     *
     * @return array<array{value: string, label: string}>
     */
    public static function assignable(): array
    {
        return collect(self::cases())
            ->filter(fn (self $role) => $role !== self::Owner)
            ->map(fn (self $role) => ['value' => $role->value, 'label' => $role->label()])
            ->values()
            ->toArray();
    }
}
