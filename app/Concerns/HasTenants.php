<?php

namespace App\Concerns;

use App\Data\TenantPermissions;
use App\Data\UserTenant;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\Membership;
use App\Models\Tenant;
use App\Support\Facades\Tenancy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

trait HasTenants
{
    /**
     * Get all of the tenants the user belongs to.
     *
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user', 'user_id', 'tenant_id')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all of the tenants the user owns.
     *
     * @return HasManyThrough<Tenant, Membership, $this>
     */
    public function ownedTenants(): HasManyThrough
    {
        return $this->hasManyThrough(
            Tenant::class,
            Membership::class,
            'user_id',
            'id',
            'id',
            'tenant_id',
        )->where('tenant_user.role', TenantRole::Owner->value);
    }

    /**
     * Get all of the memberships for the user.
     *
     * @return HasMany<Membership, $this>
     */
    public function tenantMemberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'user_id');
    }

    /**
     * Get the user's current tenant.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function currentTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'current_tenant_id');
    }

    /**
     * Get the user's personal tenant.
     */
    public function personalTenant(): ?Tenant
    {
        return $this->tenants()
            ->where('is_personal', true)
            ->first();
    }

    /**
     * Switch to the given tenant.
     */
    public function switchTenant(Tenant $tenant): bool
    {
        if (! $this->belongsToTenant($tenant)) {
            return false;
        }

        $this->update(['current_tenant_id' => $tenant->id]);
        $this->setRelation('currentTenant', $tenant);

        Tenancy::initialize($tenant);

        URL::defaults(['current_tenant' => $tenant->slug]);

        return true;
    }

    /**
     * Determine if the user belongs to the given tenant.
     */
    public function belongsToTenant(Tenant $tenant): bool
    {
        return $this->tenants()->where('tenants.id', $tenant->id)->exists();
    }

    /**
     * Determine if the given tenant is the user's current tenant.
     */
    public function isCurrentTenant(Tenant $tenant): bool
    {
        return $this->current_tenant_id === $tenant->id;
    }

    /**
     * Determine if the user is the owner of the given tenant.
     */
    public function ownsTenant(Tenant $tenant): bool
    {
        return $this->tenantRole($tenant) === TenantRole::Owner;
    }

    /**
     * Get the user's role on the given tenant.
     */
    public function tenantRole(Tenant $tenant): ?TenantRole
    {
        return $this->tenantMemberships()
            ->where('tenant_id', $tenant->id)
            ->first()
            ?->role;
    }

    /**
     * Get the user's tenants as a collection of UserTenant objects.
     *
     * @return Collection<int, UserTenant>
     */
    public function toUserTenants(bool $includeCurrent = false): Collection
    {
        return $this->tenants()
            ->get()
            ->map(fn (Tenant $tenant) => ! $includeCurrent && $this->isCurrentTenant($tenant) ? null : $this->toUserTenant($tenant))
            ->filter()
            ->values();
    }

    /**
     * Get the user's tenant as a UserTenant object.
     */
    public function toUserTenant(Tenant $tenant): UserTenant
    {
        $role = $this->tenantRole($tenant);

        return new UserTenant(
            id: $tenant->id,
            name: $tenant->name,
            slug: $tenant->slug,
            isPersonal: $tenant->is_personal,
            role: $role?->value,
            roleLabel: $role?->label(),
            isCurrent: $this->isCurrentTenant($tenant),
        );
    }

    /**
     * Get the standard permissions for a tenant as a TenantPermissions object.
     */
    public function toTenantPermissions(Tenant $tenant): TenantPermissions
    {
        $role = $this->tenantRole($tenant);

        return new TenantPermissions(
            canUpdateTenant: $role?->hasPermission(TenantPermission::UpdateTenant) ?? false,
            canDeleteTenant: $role?->hasPermission(TenantPermission::DeleteTenant) ?? false,
            canAddMember: $role?->hasPermission(TenantPermission::AddMember) ?? false,
            canUpdateMember: $role?->hasPermission(TenantPermission::UpdateMember) ?? false,
            canRemoveMember: $role?->hasPermission(TenantPermission::RemoveMember) ?? false,
            canCreateInvitation: $role?->hasPermission(TenantPermission::CreateInvitation) ?? false,
            canCancelInvitation: $role?->hasPermission(TenantPermission::CancelInvitation) ?? false,
        );
    }

    public function fallbackTenant(?Tenant $excluding = null): ?Tenant
    {
        return $this->tenants()
            ->when($excluding, fn ($query) => $query->where('tenants.id', '!=', $excluding->id))
            ->orderByRaw('LOWER(tenants.name)')
            ->first();
    }

    /**
     * Determine if the user has the given permission on the tenant.
     */
    public function hasTenantPermission(Tenant $tenant, TenantPermission $permission): bool
    {
        return $this->tenantRole($tenant)?->hasPermission($permission) ?? false;
    }
}
