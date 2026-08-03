<?php

namespace App\Http\Controllers\Tenants;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenants\UpdateTenantMemberRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TenantMemberController extends Controller
{
    /**
     * Update the specified tenant member's role.
     */
    public function update(UpdateTenantMemberRequest $request, Tenant $tenant, User $user): RedirectResponse
    {
        Gate::authorize('updateMember', $tenant);

        $newRole = TenantRole::from($request->validated('role'));

        $tenant->memberships()
            ->where('user_id', $user->id)
            ->firstOrFail()
            ->update(['role' => $newRole]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated.')]);

        return to_route('tenants.edit', ['tenant' => $tenant->slug]);
    }

    /**
     * Remove the specified tenant member.
     */
    public function destroy(Tenant $tenant, User $user): RedirectResponse
    {
        Gate::authorize('removeMember', $tenant);

        abort_if($tenant->owner()?->is($user), 403, __('The tenant owner cannot be removed.'));

        $tenant->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($user->isCurrentTenant($tenant)) {
            $user->switchTenant($user->personalTenant());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('tenants.edit', ['tenant' => $tenant->slug]);
    }
}
