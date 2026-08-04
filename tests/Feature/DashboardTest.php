<?php

use App\Models\TeamInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $user = User::factory()->withTeam()->create();

    $response = $this->get(route('dashboard', ['team' => $user->team->slug]));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->withTeam()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard', ['team' => $user->team->slug]));

    $response->assertOk();
});

test('the dashboard of another team is forbidden', function () {
    $alice = User::factory()->withTeam()->create();
    $mallory = User::factory()->withTeam()->create();

    $this->actingAs($mallory)
        ->get(route('dashboard', ['team' => $alice->team->slug]))
        ->assertForbidden();
});

test('an unknown team slug is refused rather than falling back to the user own team', function () {
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['team' => 'no-such-team']))
        ->assertForbidden();
});

test('dashboard includes pending invitations for the authenticated user', function () {
    $owner = User::factory()->withTeam('Laravel Team')->create(['name' => 'Taylor Otwell']);
    $invitedUser = User::factory()->withTeam()->create(['email' => 'invited@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard', ['team' => $invitedUser->team->slug]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 1)
        ->where('pendingInvitations.0.code', $invitation->code)
        ->where('pendingInvitations.0.inviterName', 'Taylor Otwell')
        ->where('pendingInvitations.0.teamName', 'Laravel Team')
        // The slug is deliberately not exposed: anyone reaching the dashboard
        // already has a team, so this invitation can only be declined and
        // there is nowhere to navigate to (D-020).
        ->missing('pendingInvitations.0.team'),
    );
});

test('dashboard does not include accepted invitations', function () {
    $owner = User::factory()->withTeam()->create();
    $invitedUser = User::factory()->withTeam()->create(['email' => 'invited@example.com']);

    TeamInvitation::factory()->accepted()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard', ['team' => $invitedUser->team->slug]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );
});

test('dashboard excludes expired invitations without deleting them', function () {
    $owner = User::factory()->withTeam()->create();
    $invitedUser = User::factory()->withTeam()->create(['email' => 'invited@example.com']);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard', ['team' => $invitedUser->team->slug]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('dashboard does not include or delete other users invitations', function () {
    $owner = User::factory()->withTeam()->create();
    $invitedUser = User::factory()->withTeam()->create(['email' => 'invited@example.com']);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $owner->team->id,
        'email' => 'someone@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard', ['team' => $invitedUser->team->slug]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});
