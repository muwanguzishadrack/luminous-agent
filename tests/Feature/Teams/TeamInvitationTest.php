<?php

use App\Enums\TeamRole;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Support\Facades\Notification;

test('team invitations can be created', function () {
    Notification::fake();

    $owner = User::factory()->withTeam()->create();

    $response = $this
        ->actingAs($owner)
        ->post(route('team.invitations.store'), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Agent->value,
        ]);

    $response->assertRedirect(route('team.edit'));

    $this->assertDatabaseHas('team_invitations', [
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Agent->value,
    ]);
});

test('invitation email points at the join page rather than a dashboard', function () {
    $owner = User::factory()->withTeam()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => $invitedUser->email,
        'invited_by' => $owner->id,
    ]);

    $mail = (new TeamInvitationNotification($invitation))->toMail($invitedUser);

    expect($mail->actionUrl)->toBe(route('invitations.join', $invitation));

    // The invitee is not a member of anything yet, so the mail must not send
    // them to a dashboard they cannot reach (D-020).
    $this->assertStringNotContainsString(
        'dashboard',
        strtolower(implode(' ', $mail->introLines)),
    );
});

test('invitation email tells a new invitee they will choose a password', function () {
    $owner = User::factory()->withTeam()->create();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => 'unknown@example.com',
        'invited_by' => $owner->id,
    ]);

    $mail = (new TeamInvitationNotification($invitation))->toMail((object) []);

    expect($mail->actionUrl)->toBe(route('invitations.join', $invitation));

    // Someone with no account must be told what the link will ask of them —
    // the old copy said "log in", a dead end for the commonest case.
    $this->assertStringContainsString(
        'password',
        strtolower(implode(' ', $mail->introLines)),
    );
});

test('team invitations can be created by admins', function () {
    Notification::fake();

    $owner = User::factory()->withTeam()->create();
    $admin = User::factory()->memberOf($owner->team, TeamRole::Admin)->create();

    $response = $this
        ->actingAs($admin)
        ->post(route('team.invitations.store'), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Agent->value,
        ]);

    $response->assertRedirect(route('team.edit'));
});

test('existing team members cannot be invited', function () {
    Notification::fake();

    $owner = User::factory()->withTeam()->create();
    User::factory()->memberOf($owner->team, TeamRole::Agent)->create(['email' => 'member@example.com']);

    $response = $this
        ->actingAs($owner)
        ->post(route('team.invitations.store'), [
            'email' => 'member@example.com',
            'role' => TeamRole::Agent->value,
        ]);

    $response->assertSessionHasErrors('email');
});

/**
 * One team per user (D-020): somebody who already runs a team of their own
 * cannot be recruited into another, and we say so at invitation time rather
 * than letting them discover it when accepting.
 */
test('a person who already belongs to another team cannot be invited', function () {
    Notification::fake();

    $owner = User::factory()->withTeam()->create();
    User::factory()->withTeam()->create(['email' => 'busy@example.com']);

    $response = $this
        ->actingAs($owner)
        ->post(route('team.invitations.store'), [
            'email' => 'busy@example.com',
            'role' => TeamRole::Agent->value,
        ]);

    $response->assertSessionHasErrors(['email' => 'This person already belongs to another team. A person can only belong to one team — they need a separate login for yours.']);

    $this->assertDatabaseMissing('team_invitations', ['email' => 'busy@example.com']);
});

test('duplicate invitations cannot be created', function () {
    Notification::fake();

    $owner = User::factory()->withTeam()->create();

    TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($owner)
        ->post(route('team.invitations.store'), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Agent->value,
        ]);

    $response->assertSessionHasErrors('email');
});

test('team invitations cannot be created by members', function () {
    $owner = User::factory()->withTeam()->create();
    $member = User::factory()->memberOf($owner->team, TeamRole::Agent)->create();

    $response = $this
        ->actingAs($member)
        ->post(route('team.invitations.store'), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Agent->value,
        ]);

    $response->assertForbidden();
});

test('team invitations can be cancelled by owners', function () {
    $owner = User::factory()->withTeam()->create();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('team.invitations.destroy', $invitation));

    $response->assertRedirect(route('team.edit'));

    $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
});

test('team invitations can be accepted by a user without a team', function () {
    $owner = User::factory()->withTeam()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Agent,
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('invitations.accept', $invitation));

    $response->assertRedirect(route('dashboard', ['team' => $owner->team->slug]));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Invitation accepted.']);

    expect($invitedUser->fresh()->belongsToTeam($owner->team))->toBeTrue()
        ->and($invitedUser->fresh()->teamRole($owner->team))->toBe(TeamRole::Agent)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
});

/**
 * The one-team cap holds at accept time too, with a message that explains it
 * rather than a 500 from the unique index (D-020).
 */
test('a user who already belongs to a team cannot accept an invitation to another', function () {
    $owner = User::factory()->withTeam()->create();
    $invitedUser = User::factory()->withTeam()->create(['email' => 'invited@example.com']);
    $ownTeam = $invitedUser->team;

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Agent,
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('invitations.accept', $invitation));

    $response->assertSessionHasErrors('invitation');

    expect($invitation->fresh()->accepted_at)->toBeNull()
        ->and($invitedUser->fresh()->belongsToTeam($ownTeam))->toBeTrue()
        ->and($invitedUser->fresh()->belongsToTeam($owner->team))->toBeFalse();
});

test('team invitations can be declined by the invited user', function () {
    $owner = User::factory()->withTeam()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this
        ->actingAs($invitedUser)
        ->delete(route('invitations.decline', $invitation))
        ->assertRedirect();

    $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
});

test('a user with a team may still decline an invitation', function () {
    $owner = User::factory()->withTeam()->create();
    $invitedUser = User::factory()->withTeam()->create(['email' => 'invited@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this
        ->actingAs($invitedUser)
        ->delete(route('invitations.decline', $invitation))
        ->assertRedirect();

    $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
});

test('team invitations cannot be declined by uninvited user', function () {
    $owner = User::factory()->withTeam()->create();
    $uninvitedUser = User::factory()->create(['email' => 'uninvited@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($uninvitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertSessionHasErrors('invitation');

    $this->assertDatabaseHas('team_invitations', ['id' => $invitation->id]);
});

test('accepted team invitations cannot be declined', function () {
    $owner = User::factory()->withTeam()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);

    $invitation = TeamInvitation::factory()->accepted()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertSessionHasErrors('invitation');

    $this->assertDatabaseHas('team_invitations', ['id' => $invitation->id]);
});

test('team invitations cannot be accepted by uninvited user', function () {
    $owner = User::factory()->withTeam()->create();
    $uninvitedUser = User::factory()->create(['email' => 'uninvited@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($uninvitedUser)
        ->get(route('invitations.accept', $invitation));

    $response->assertSessionHasErrors('invitation');

    expect($uninvitedUser->fresh()->belongsToAnyTeam())->toBeFalse();
});

test('expired invitations cannot be accepted', function () {
    $owner = User::factory()->withTeam()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('invitations.accept', $invitation));

    $response->assertSessionHasErrors('invitation');

    expect($invitedUser->fresh()->belongsToAnyTeam())->toBeFalse();
});
