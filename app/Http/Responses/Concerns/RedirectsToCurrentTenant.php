<?php

namespace App\Http\Responses\Concerns;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

trait RedirectsToCurrentTenant
{
    protected function redirectPathForCurrentTenant(Request $request, string $redirect): string
    {
        $tenant = $this->currentTenant($request);

        URL::defaults(['current_tenant' => $tenant->slug]);

        return "/{$tenant->slug}{$redirect}";
    }

    protected function currentTenant(Request $request): Tenant
    {
        $user = $request->user();

        abort_if(! $user, 403);

        $tenant = $user->currentTenant ?? $user->personalTenant();

        abort_if(! $tenant, 403);

        return $tenant;
    }
}
