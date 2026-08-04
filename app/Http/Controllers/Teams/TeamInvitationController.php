<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\AcceptTeamInvitation;
use App\Actions\Teams\JoinTeamFromInvitation;
use App\Data\PendingInvitation;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\CreateTeamInvitationRequest;
use App\Http\Requests\Teams\JoinTeamRequest;
use App\Http\Requests\Teams\RespondToTeamInvitationRequest;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use App\Support\Facades\Teams;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class TeamInvitationController extends Controller
{
    /**
     * The invitations addressed to the signed-in user.
     *
     * Deliberately outside the {team} prefix: the people who most need this
     * page are the ones with no team to prefix it with (D-020). Without it an
     * invitee whose account already exists has nowhere to accept from — the
     * dashboard modal lives behind {team}, so they never see it.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('invitations/index', [
            'invitations' => $request->user()
                ->pendingInvitations()
                ->get()
                ->map(PendingInvitation::fromModel(...))
                ->values(),
            // Drives the copy: someone already on a team may only decline.
            'belongsToTeam' => $request->user()->belongsToAnyTeam(),
        ]);
    }

    /**
     * The invitee's landing page — the target of the link in their email.
     *
     * Public by necessity: the whole point is that they have no account yet.
     * The 64-character code is the credential, and it only ever yields the
     * team name, the inviter, and the role being offered.
     */
    public function join(Request $request, TeamInvitation $invitation): RedirectResponse|Response
    {
        if ($request->user() !== null) {
            // Already signed in — the accept/decline surface handles them,
            // including the case where they already belong to a team.
            return to_route('invitations.index');
        }

        if (! $invitation->isPending()) {
            return Inertia::render('invitations/unavailable', [
                'reason' => $invitation->isAccepted() ? 'accepted' : 'expired',
                'teamName' => $invitation->team->name,
            ]);
        }

        // An address we already know cannot register again; they authenticate
        // and pick the invitation up from /invitations instead.
        if (User::query()->whereRaw('LOWER(email) = ?', [strtolower($invitation->email)])->exists()) {
            return redirect()->route('login', ['invitation' => $invitation->code]);
        }

        return Inertia::render('invitations/join', [
            'invitation' => [
                'code' => $invitation->code,
                'email' => $invitation->email,
                'teamName' => $invitation->team->name,
                'inviterName' => $invitation->inviter->name,
                'roleLabel' => $invitation->role->label(),
            ],
        ]);
    }

    /**
     * Create the invitee's account and join them to the team.
     */
    public function storeMember(
        JoinTeamRequest $request,
        TeamInvitation $invitation,
        JoinTeamFromInvitation $join,
    ): RedirectResponse {
        // Re-checked here, not just in join(): the form may have sat open
        // while the invitation expired or was cancelled.
        abort_unless($invitation->isPending(), 410, 'This invitation is no longer valid.');

        abort_if(
            User::query()->whereRaw('LOWER(email) = ?', [strtolower($invitation->email)])->exists(),
            409,
            'An account already exists for this email address.',
        );

        $user = $join->handle(
            $invitation,
            $request->validated('name'),
            $request->validated('password'),
        );

        Auth::login($user);

        // The session existed before authentication — rotate it.
        $request->session()->regenerate();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Welcome to :team.', ['team' => $invitation->team->name]),
        ]);

        return to_route('dashboard', ['team' => $invitation->team->slug]);
    }

    /**
     * Store a newly created invitation.
     */
    public function store(CreateTeamInvitationRequest $request): RedirectResponse
    {
        $team = Teams::currentOrFail();

        Gate::authorize('inviteMember', $team);

        $invitation = $team->invitations()->create([
            'email' => $request->validated('email'),
            'role' => TeamRole::from($request->validated('role')),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(3),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('team.edit');
    }

    /**
     * Cancel the specified invitation.
     */
    public function destroy(TeamInvitation $invitation): RedirectResponse
    {
        $team = Teams::currentOrFail();

        abort_unless($invitation->team_id === $team->id, 404);

        Gate::authorize('cancelInvitation', $team);

        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation cancelled.')]);

        return to_route('team.edit');
    }

    /**
     * Accept the invitation. Only a user with no team can — a second
     * membership is refused by the rule and by the database (D-020).
     */
    public function accept(RespondToTeamInvitationRequest $request, TeamInvitation $invitation, AcceptTeamInvitation $accept): RedirectResponse
    {
        $team = $accept->handle($request->user(), $invitation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation accepted.')]);

        // The URL default was resolved before the user had a team — name it.
        return to_route('dashboard', ['team' => $team->slug]);
    }

    /**
     * Decline the invitation.
     */
    public function decline(RespondToTeamInvitationRequest $request, TeamInvitation $invitation): RedirectResponse
    {
        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation declined.')]);

        return back();
    }
}
