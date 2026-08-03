<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Tenant $tenant): bool
    {
        return $user->belongsToTenant($tenant);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Tenant $tenant): bool
    {
        return $user->hasTenantPermission($tenant, TenantPermission::UpdateTenant);
    }

    /**
     * Determine whether the user can leave the tenant.
     */
    public function leave(User $user, Tenant $tenant): bool
    {
        return ! $tenant->is_personal
            && $user->belongsToTenant($tenant)
            && ! $user->ownsTenant($tenant);
    }

    /**
     * Determine whether the user can add a member to the tenant.
     */
    public function addMember(User $user, Tenant $tenant): bool
    {
        return $user->hasTenantPermission($tenant, TenantPermission::AddMember);
    }

    /**
     * Determine whether the user can update a member's role in the tenant.
     */
    public function updateMember(User $user, Tenant $tenant): bool
    {
        return $user->hasTenantPermission($tenant, TenantPermission::UpdateMember);
    }

    /**
     * Determine whether the user can remove a member from the tenant.
     */
    public function removeMember(User $user, Tenant $tenant): bool
    {
        return $user->hasTenantPermission($tenant, TenantPermission::RemoveMember);
    }

    /**
     * Determine whether the user can invite members to the tenant.
     */
    public function inviteMember(User $user, Tenant $tenant): bool
    {
        return $user->hasTenantPermission($tenant, TenantPermission::CreateInvitation);
    }

    /**
     * Determine whether the user can cancel invitations.
     */
    public function cancelInvitation(User $user, Tenant $tenant): bool
    {
        return $user->hasTenantPermission($tenant, TenantPermission::CancelInvitation);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Tenant $tenant): bool
    {
        return ! $tenant->is_personal && $user->hasTenantPermission($tenant, TenantPermission::DeleteTenant);
    }
}
