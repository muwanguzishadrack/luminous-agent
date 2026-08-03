export type TenantRole = 'owner' | 'admin' | 'supervisor' | 'agent' | 'viewer';

export type Tenant = {
    id: string;
    name: string;
    slug: string;
    isPersonal: boolean;
    role?: TenantRole;
    roleLabel?: string;
    isCurrent?: boolean;
};

export type TenantMember = {
    id: string;
    name: string;
    email: string;
    avatar?: string | null;
    role: TenantRole;
    role_label: string;
};

export type TenantInvitation = {
    code: string;
    email: string;
    role: TenantRole;
    role_label: string;
    created_at: string;
};

export type TenantInvitationContext = {
    code: string;
    tenantName: string;
};

export type DashboardInvitation = {
    code: string;
    inviterName: string;
    tenant: {
        name: string;
        slug: string;
    };
};

export type TenantPermissions = {
    canUpdateTenant: boolean;
    canDeleteTenant: boolean;
    canAddMember: boolean;
    canUpdateMember: boolean;
    canRemoveMember: boolean;
    canCreateInvitation: boolean;
    canCancelInvitation: boolean;
};

export type RoleOption = {
    value: TenantRole;
    label: string;
};
