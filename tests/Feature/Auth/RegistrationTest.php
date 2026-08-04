<?php

use App\Enums\TeamRole;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Facades\Teams;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('registration screen includes team invitation context', function () {
    $owner = User::factory()->withTeam('Laravel Team')->create();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this->get(route('register', ['invitation' => $invitation->code]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/register')
        ->where('teamInvitation.code', $invitation->code)
        ->where('teamInvitation.teamName', 'Laravel Team'),
    );
});

/**
 * A team is created exactly once, at registration (D-020).
 */
test('new users can register and get their own team', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();

    $user = User::where('email', 'test@example.com')->sole();
    $team = $user->team;

    expect($team)->not->toBeNull()
        ->and($team->name)->toBe("Test User's Team")
        ->and($user->teamRole($team))->toBe(TeamRole::Owner);

    $response->assertRedirect(route('dashboard', ['team' => $team->slug]));
});

/**
 * Registering against an invitation joins the inviting team rather than
 * creating a second one — otherwise an invited person could never accept.
 */
test('registering with an invitation joins that team instead of creating one', function () {
    $owner = User::factory()->withTeam('Acme Stores')->create();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Supervisor,
        'invited_by' => $owner->id,
    ]);

    $response = $this->post(route('register.store'), [
        'name' => 'Invited Person',
        'email' => 'invited@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation' => $invitation->code,
    ]);

    $user = User::where('email', 'invited@example.com')->sole();

    Teams::actingAs($user);

    expect($user->team->id)->toBe($owner->team->id)
        ->and($user->teamRole($owner->team))->toBe(TeamRole::Supervisor)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and(DB::table('teams')->count())->toBe(1);

    $response->assertRedirect(route('dashboard', ['team' => $owner->team->slug]));
});

test('an invitation addressed to somebody else is ignored at registration', function () {
    $owner = User::factory()->withTeam()->create();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $owner->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->post(route('register.store'), [
        'name' => 'Opportunist',
        'email' => 'someone.else@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation' => $invitation->code,
    ]);

    $user = User::where('email', 'someone.else@example.com')->sole();

    Teams::actingAs($user);

    expect($user->team->id)->not->toBe($owner->team->id)
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});
