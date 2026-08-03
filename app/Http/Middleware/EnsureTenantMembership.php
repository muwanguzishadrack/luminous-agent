<?php

namespace App\Http\Middleware;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Facades\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantMembership
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $minimumRole = null): Response
    {
        [$user, $tenant] = [$request->user(), $this->tenant($request)];

        abort_if(! $user || ! $tenant || ! $user->belongsToTenant($tenant), 403);

        $this->ensureTenantMemberHasRequiredRole($user, $tenant, $minimumRole);

        if ($request->route('current_tenant') && ! $user->isCurrentTenant($tenant)) {
            $user->switchTenant($tenant);
        }

        // The user is a verified member of the route's tenant: it becomes the
        // authoritative context for this request (overrides the default set
        // by EstablishTenancyContext).
        Tenancy::initialize($tenant);

        return $next($request);
    }

    /**
     * Ensure the given user has at least the given role, if applicable.
     */
    protected function ensureTenantMemberHasRequiredRole(User $user, Tenant $tenant, ?string $minimumRole): void
    {
        if ($minimumRole === null) {
            return;
        }

        $role = $user->tenantRole($tenant);

        $requiredRole = TenantRole::tryFrom($minimumRole);

        abort_if(
            $requiredRole === null ||
            $role === null ||
            ! $role->isAtLeast($requiredRole),
            403,
        );
    }

    /**
     * Get the tenant associated with the request.
     */
    protected function tenant(Request $request): ?Tenant
    {
        $tenant = $request->route('current_tenant') ?? $request->route('tenant');

        if (is_string($tenant)) {
            $tenant = Tenant::where('slug', $tenant)->first();
        }

        return $tenant;
    }
}
