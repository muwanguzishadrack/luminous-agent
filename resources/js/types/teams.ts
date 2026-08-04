export type TeamRole = 'owner' | 'admin' | 'supervisor' | 'agent' | 'viewer';

export type Team = {
    id: string;
    name: string;
    slug: string;
    role?: TeamRole;
    roleLabel?: string;
};

export type TeamMember = {
    id: string;
    name: string;
    email: string;
    avatar?: string | null;
    role: TeamRole;
    role_label: string;
};

export type TeamInvitation = {
    code: string;
    email: string;
    role: TeamRole;
    role_label: string;
    created_at: string;
};

export type TeamInvitationContext = {
    code: string;
    teamName: string;
};

/** Mirrors App\Data\PendingInvitation. */
export type PendingInvitation = {
    code: string;
    inviterName: string;
    teamName: string;
    roleLabel: string;
};

export type TeamPermissions = {
    canUpdateTeam: boolean;
    canDeleteTeam: boolean;
    canAddMember: boolean;
    canUpdateMember: boolean;
    canRemoveMember: boolean;
    canCreateInvitation: boolean;
    canCancelInvitation: boolean;
};

export type RoleOption = {
    value: TeamRole;
    label: string;
};
