<?php

use App\Enums\TeamRole;
use App\Models\TeamInvitation;
use App\Models\User;

/**
 * The invitee sets a password and joins in one step. Before this route they
 * were sent to the generic registration form, which asks for an email and only
 * honours the invitation when the typed address happens to match — so anyone
 * who preferred a different address silently got a team of their own instead
 * of joining. That is the wrong-workspace failure D-020 exists to prevent.
 */
function pendingInvitation(User $owner, string $email = 'grace@example.test', TeamRole $role = TeamRole::Agent): TeamInvitation
{
    return TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => $email,
        'role' => $role,
        'invited_by' => $owner->id,
    ]);
}

test('the join page shows the invitation and locks the email', function () {
    $owner = User::factory()->withTeam('Luminous')->create(['name' => 'Demo Owner']);
    $invitation = pendingInvitation($owner);

    $this->get(route('invitations.join', $invitation))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('invitations/join')
            ->where('invitation.email', 'grace@example.test')
            ->where('invitation.teamName', 'Luminous')
            ->where('invitation.inviterName', 'Demo Owner')
            ->where('invitation.roleLabel', TeamRole::Agent->label()));
});

test('an invitee sets a password and joins the team in one step', function () {
    $owner = User::factory()->withTeam('Luminous')->create();
    $invitation = pendingInvitation($owner);

    $this->post(route('invitations.join.store', $invitation), [
        'name' => 'Grace Nakato',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertRedirect(route('dashboard', ['team' => $owner->team->slug]));

    $grace = User::where('email', 'grace@example.test')->sole();

    expect($grace->name)->toBe('Grace Nakato')
        ->and($grace->belongsToTeam($owner->team))->toBeTrue()
        ->and($grace->teamRole($owner->team))->toBe(TeamRole::Agent)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();

    $this->assertAuthenticatedAs($grace);
});

/**
 * Receiving the code proves control of the mailbox, which is what verification
 * establishes — a second confirmation link would prove nothing further.
 */
test('joining marks the email as verified', function () {
    $owner = User::factory()->withTeam()->create();
    $invitation = pendingInvitation($owner);

    $this->post(route('invitations.join.store', $invitation), [
        'name' => 'Grace Nakato',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ]);

    expect(User::where('email', 'grace@example.test')->sole()->email_verified_at)->not->toBeNull();
});

/**
 * The account is created against the invitation's address, so a forged email
 * field cannot redirect the invitation to somebody else.
 */
test('an email supplied in the form is ignored', function () {
    $owner = User::factory()->withTeam()->create();
    $invitation = pendingInvitation($owner);

    $this->post(route('invitations.join.store', $invitation), [
        'name' => 'Grace Nakato',
        'email' => 'attacker@example.test',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ]);

    expect(User::where('email', 'attacker@example.test')->exists())->toBeFalse()
        ->and(User::where('email', 'grace@example.test')->exists())->toBeTrue();
});

test('the password must be confirmed', function () {
    $owner = User::factory()->withTeam()->create();
    $invitation = pendingInvitation($owner);

    $this->post(route('invitations.join.store', $invitation), [
        'name' => 'Grace Nakato',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'something-else',
    ])->assertSessionHasErrors('password');

    expect(User::where('email', 'grace@example.test')->exists())->toBeFalse();
});

test('an expired invitation cannot be used to join', function () {
    $owner = User::factory()->withTeam()->create();
    $invitation = pendingInvitation($owner);
    $invitation->update(['expires_at' => now()->subDay()]);

    $this->get(route('invitations.join', $invitation))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('invitations/unavailable')
            ->where('reason', 'expired'));

    $this->post(route('invitations.join.store', $invitation), [
        'name' => 'Grace Nakato',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertStatus(410);

    expect(User::where('email', 'grace@example.test')->exists())->toBeFalse();
});

test('an accepted invitation cannot be reused', function () {
    $owner = User::factory()->withTeam()->create();
    $invitation = pendingInvitation($owner);
    $invitation->update(['accepted_at' => now()]);

    $this->get(route('invitations.join', $invitation))
        ->assertInertia(fn ($page) => $page
            ->component('invitations/unavailable')
            ->where('reason', 'accepted'));
});

/**
 * Someone whose account already exists must authenticate rather than create a
 * second one — the invitation is picked up from /invitations after login.
 */
test('an invitee who already has an account is sent to sign in', function () {
    $owner = User::factory()->withTeam()->create();
    $invitation = pendingInvitation($owner);
    User::factory()->create(['email' => 'grace@example.test']);

    $this->get(route('invitations.join', $invitation))
        ->assertRedirect(route('login', ['invitation' => $invitation->code]));

    $this->post(route('invitations.join.store', $invitation), [
        'name' => 'Impostor',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertStatus(409);
});

test('a signed-in visitor is sent to their invitations instead', function () {
    $owner = User::factory()->withTeam()->create();
    $invitation = pendingInvitation($owner);
    $someone = User::factory()->create();

    $this->actingAs($someone)
        ->get(route('invitations.join', $invitation))
        ->assertRedirect(route('invitations.index'));
});

test('the invitation email links to the join page', function () {
    $owner = User::factory()->withTeam()->create();
    $invitation = pendingInvitation($owner);

    $mail = (new App\Notifications\Teams\TeamInvitation($invitation))->toMail((object) []);

    expect($mail->actionUrl)->toBe(route('invitations.join', $invitation));
});
