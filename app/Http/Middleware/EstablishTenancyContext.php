<?php

namespace App\Http\Middleware;

use App\Support\Facades\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifies the authenticated user to the database session and establishes
 * the user's current tenant as the default context. Route-level tenant
 * resolution (EnsureTenantMembership) runs later and takes precedence.
 */
class EstablishTenancyContext
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        Tenancy::actingAs($user);

        if ($user?->currentTenant) {
            Tenancy::initialize($user->currentTenant);
        }

        return $next($request);
    }
}
