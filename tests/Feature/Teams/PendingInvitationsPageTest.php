<?php

use App\Enums\TeamRole;
use App\Models\TeamInvitation;
use App\Models\User;

/**
 * The standalone invitations page exists because a user with no team has no
 * team-prefixed page to be shown anything on (D-020). Before it, an invitee
 * whose account already existed logged in, landed on their profile, and had
 * no route back to the invitation in their inbox.
 */
test('a user without a team can see their pending invitation', function () {
    $owner = User::factory()->withTeam()->create();
    $invited = User::factory()->create(['email' => 'invited@example.com']);

    TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Agent,
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invited)
        ->get(route('invitations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('invitations/index')
            ->where('belongsToTeam', false)
            ->has('invitations', 1)
            ->where('invitations.0.teamName', $owner->team->name)
            ->where('invitations.0.inviterName', $owner->name)
            ->where('invitations.0.roleLabel', TeamRole::Agent->label()));
});

test('a user without a team can accept from the invitations page', function () {
    $owner = User::factory()->withTeam()->create();
    $invited = User::factory()->create(['email' => 'invited@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Agent,
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invited)
        ->get(route('invitations.accept', $invitation))
        ->assertRedirect(route('dashboard', ['team' => $owner->team->slug]));

    expect($invited->fresh()->belongsToTeam($owner->team))->toBeTrue();
});

/**
 * The page must never become a directory of other people's invitations —
 * team_invitations carries no RLS policy, so the email match is the only
 * boundary and it is worth pinning.
 */
test('the invitations page shows only invitations addressed to the viewer', function () {
    $owner = User::factory()->withTeam()->create();
    $viewer = User::factory()->create(['email' => 'mine@example.com']);

    TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => 'mine@example.com',
        'invited_by' => $owner->id,
    ]);

    TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => 'somebody-else@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($viewer)
        ->get(route('invitations.index'))
        ->assertInertia(fn ($page) => $page
            ->has('invitations', 1)
            ->where('invitations.0.teamName', $owner->team->name));
});

test('expired invitations do not appear on the invitations page', function () {
    $owner = User::factory()->withTeam()->create();
    $invited = User::factory()->create(['email' => 'invited@example.com']);

    TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
        'expires_at' => now()->subDay(),
    ]);

    $this->actingAs($invited)
        ->get(route('invitations.index'))
        ->assertInertia(fn ($page) => $page->has('invitations', 0));
});

/**
 * Someone who already has a team may only decline — the page says so rather
 * than offering an Accept that the one-team cap would reject.
 */
test('a user with a team is shown decline-only copy', function () {
    $owner = User::factory()->withTeam()->create();
    $invited = User::factory()->withTeam()->create(['email' => 'invited@example.com']);

    TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invited)
        ->get(route('invitations.index'))
        ->assertInertia(fn ($page) => $page
            ->where('belongsToTeam', true)
            ->has('invitations', 1));
});

test('the invitations page requires authentication', function () {
    $this->get(route('invitations.index'))->assertRedirect(route('login'));
});

/**
 * The redirect is the half of the fix that matters most: the emailed link
 * carries the invitation code to the login screen, and login must not drop it.
 */
test('logging in without a team lands on the pending invitation', function () {
    $owner = User::factory()->withTeam()->create();
    $invited = User::factory()->create([
        'email' => 'invited@example.com',
        'password' => 'password',
    ]);

    TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->post(route('login.store'), [
        'email' => 'invited@example.com',
        'password' => 'password',
    ])->assertRedirect(route('invitations.index'));

    expect($invited->fresh()->belongsToAnyTeam())->toBeFalse();
});

/**
 * The invitation email links to /login. A signed-in visitor is bounced off
 * guest routes by RedirectIfAuthenticated, which defaults to route('dashboard')
 * — and that needs a {team} segment a teamless user cannot supply. It threw a
 * UrlGenerationException (a 500) on precisely the invitee's path.
 */
test('an authenticated user without a team is not 500ed by a guest route', function () {
    $owner = User::factory()->withTeam()->create();
    $invited = User::factory()->create(['email' => 'invited@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invited)
        ->get(route('login', ['invitation' => $invitation->code]))
        ->assertRedirect(route('invitations.index'));
});

test('an authenticated user with a team is still sent to their dashboard', function () {
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user)
        ->get(route('login'))
        ->assertRedirect("/{$user->team->slug}/dashboard");
});

test('an authenticated teamless user with no invitation lands on their profile', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('login'))
        ->assertRedirect(route('profile.edit'));
});

test('logging in without a team or invitation still lands on the profile', function () {
    User::factory()->create([
        'email' => 'teamless@example.com',
        'password' => 'password',
    ]);

    $this->post(route('login.store'), [
        'email' => 'teamless@example.com',
        'password' => 'password',
    ])->assertRedirect(route('profile.edit'));
});
