<?php

namespace App\Http\Controllers\Tenants;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenants\CreateTenantInvitationRequest;
use App\Http\Requests\Tenants\RespondToTenantInvitationRequest;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Notifications\Tenants\TenantInvitation as TenantInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;

class TenantInvitationController extends Controller
{
    /**
     * Store a newly created invitation.
     */
    public function store(CreateTenantInvitationRequest $request, Tenant $tenant): RedirectResponse
    {
        Gate::authorize('inviteMember', $tenant);

        $invitation = $tenant->invitations()->create([
            'email' => $request->validated('email'),
            'role' => TenantRole::from($request->validated('role')),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(3),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new TenantInvitationNotification($invitation));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('tenants.edit', ['tenant' => $tenant->slug]);
    }

    /**
     * Cancel the specified invitation.
     */
    public function destroy(Tenant $tenant, TenantInvitation $invitation): RedirectResponse
    {
        abort_unless($invitation->tenant_id === $tenant->id, 404);

        Gate::authorize('cancelInvitation', $tenant);

        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation cancelled.')]);

        return to_route('tenants.edit', ['tenant' => $tenant->slug]);
    }

    /**
     * Accept the invitation.
     */
    public function accept(RespondToTenantInvitationRequest $request, TenantInvitation $invitation): RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user, $invitation) {
            $tenant = $invitation->tenant;

            $tenant->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $invitation->role],
            );

            $invitation->update(['accepted_at' => now()]);

            $user->switchTenant($tenant);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation accepted.')]);

        return to_route('dashboard');
    }

    /**
     * Decline the invitation.
     */
    public function decline(RespondToTenantInvitationRequest $request, TenantInvitation $invitation): RedirectResponse
    {
        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation declined.')]);

        return to_route('dashboard');
    }
}
