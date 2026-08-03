<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetTenantUrlDefaults
{
    /**
     * Set the default URL parameters for tenant-based routes.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($currentTenant = $request->user()?->currentTenant) {
            URL::defaults([
                'current_tenant' => $currentTenant->slug,
                'tenant' => $currentTenant->slug,
            ]);
        }

        return $next($request);
    }
}
