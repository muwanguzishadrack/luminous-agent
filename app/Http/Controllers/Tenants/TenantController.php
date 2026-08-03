<?php

namespace App\Http\Controllers\Tenants;

use App\Actions\Tenants\CreateTenant;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenants\DeleteTenantRequest;
use App\Http\Requests\Tenants\SaveTenantRequest;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    /**
     * Display a listing of the user's tenants.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('tenants/index', [
            'tenants' => $user->toUserTenants(includeCurrent: true),
        ]);
    }

    /**
     * Store a newly created tenant.
     */
    public function store(SaveTenantRequest $request, CreateTenant $createTenant): RedirectResponse
    {
        $tenant = $createTenant->handle($request->user(), $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tenant created.')]);

        return to_route('tenants.edit', ['tenant' => $tenant->slug]);
    }

    /**
     * Show the tenant edit page.
     */
    public function edit(Request $request, Tenant $tenant): Response
    {
        $user = $request->user();

        return Inertia::render('tenants/edit', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'isPersonal' => $tenant->is_personal,
            ],
            'members' => $tenant->members()->get()->map(function (User $member) {
                /** @var Membership $membership */
                $membership = $member->getRelation('pivot');

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar' => $member->avatar ?? null,
                    'role' => $membership->role->value,
                    'role_label' => $membership->role->label(),
                ];
            }),
            'invitations' => $tenant->invitations()
                ->whereNull('accepted_at')
                ->get()
                ->map(fn ($invitation) => [
                    'code' => $invitation->code,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'role_label' => $invitation->role->label(),
                    'created_at' => $invitation->created_at->toISOString(),
                ]),
            'permissions' => $user->toTenantPermissions($tenant),
            'availableRoles' => TenantRole::assignable(),
        ]);
    }

    /**
     * Update the specified tenant.
     */
    public function update(SaveTenantRequest $request, Tenant $tenant): RedirectResponse
    {
        Gate::authorize('update', $tenant);

        $tenant = DB::transaction(function () use ($request, $tenant) {
            $tenant = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            $tenant->update(['name' => $request->validated('name')]);

            return $tenant;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tenant updated.')]);

        return to_route('tenants.edit', ['tenant' => $tenant->slug]);
    }

    /**
     * Switch the user's current tenant.
     */
    public function switch(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless($request->user()->belongsToTenant($tenant), 403);

        $request->user()->switchTenant($tenant);

        return back();
    }

    /**
     * Leave the specified tenant.
     */
    public function leave(Request $request, Tenant $tenant): RedirectResponse
    {
        Gate::authorize('leave', $tenant);

        $user = $request->user();

        $fallbackTenant = $user->isCurrentTenant($tenant)
            ? $user->fallbackTenant($tenant)
            : null;

        $tenant->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($fallbackTenant) {
            $user->switchTenant($fallbackTenant);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You left the tenant ":name"', ['name' => $tenant->name])]);

        return to_route('tenants.index');
    }

    /**
     * Delete the specified tenant.
     */
    public function destroy(DeleteTenantRequest $request, Tenant $tenant): RedirectResponse
    {
        $user = $request->user();
        $fallbackTenant = $user->isCurrentTenant($tenant)
            ? $user->fallbackTenant($tenant)
            : null;

        DB::transaction(function () use ($user, $tenant) {
            User::where('current_tenant_id', $tenant->id)
                ->where('id', '!=', $user->id)
                ->each(fn (User $affectedUser) => $affectedUser->switchTenant($affectedUser->personalTenant()));

            $tenant->invitations()->delete();
            $tenant->memberships()->delete();
            $tenant->delete();
        });

        if ($fallbackTenant) {
            $user->switchTenant($fallbackTenant);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tenant deleted.')]);

        return to_route('tenants.index');
    }
}
