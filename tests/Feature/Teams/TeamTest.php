<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Facades\Teams;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * One team per user (D-020): there is no index, no switcher and no create
 * screen — registration makes the one team, and this page maintains it.
 */
test('the team settings page can be rendered', function () {
    $user = User::factory()->withTeam()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('team.edit'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/team')
            ->where('members.0.role', TeamRole::Owner->value)
            ->where('members.0.role_label', TeamRole::Owner->label())
            ->where('team.name', $user->team->name),
        );
});

test('a user without a team cannot reach the team settings page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('team.edit'))
        ->assertForbidden();
});

test('teams can be updated by owners', function () {
    $user = User::factory()->withTeam('Original Name')->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('team.update'), [
            'name' => 'Updated Name',
        ]);

    $response->assertRedirect(route('team.edit'));

    $this->assertDatabaseHas('teams', [
        'id' => $user->team->id,
        'name' => 'Updated Name',
    ]);
});

test('teams cannot be updated by members', function () {
    $owner = User::factory()->withTeam()->create();
    $member = User::factory()->memberOf($owner->team)->create();

    $response = $this
        ->actingAs($member)
        ->patch(route('team.update'), [
            'name' => 'Updated Name',
        ]);

    $response->assertForbidden();
});

test('team slug uses next available suffix', function () {
    Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    Team::factory()->create(['name' => 'Acme One', 'slug' => 'acme-1']);
    Team::factory()->create(['name' => 'Acme Ten', 'slug' => 'acme-10']);

    $user = User::factory()->withTeam()->create();

    $this
        ->actingAs($user)
        ->patch(route('team.update'), ['name' => 'Acme']);

    $this->assertDatabaseHas('teams', [
        'id' => $user->team->id,
        'name' => 'Acme',
        'slug' => 'acme-11',
    ]);
});

test('teams can be deleted by owners', function () {
    $user = User::factory()->withTeam()->create();
    $team = $user->team;

    $response = $this
        ->actingAs($user)
        ->delete(route('team.destroy'), ['name' => $team->name]);

    $response->assertRedirect(route('profile.edit'));

    $this->assertSoftDeleted('teams', ['id' => $team->id]);

    // Deleting the only team leaves everyone on it without one — the honest
    // consequence of one team per user (D-020).
    expect($user->fresh()->belongsToAnyTeam())->toBeFalse();
});

test('team deletion requires name confirmation', function () {
    $user = User::factory()->withTeam()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('team.destroy'), ['name' => 'Wrong Name']);

    $response->assertSessionHasErrors('name');

    $this->assertDatabaseHas('teams', [
        'id' => $user->team->id,
        'deleted_at' => null,
    ]);
});

test('teams cannot be deleted by non owners', function () {
    $owner = User::factory()->withTeam()->create();
    $member = User::factory()->memberOf($owner->team)->create();

    $response = $this
        ->actingAs($member)
        ->delete(route('team.destroy'), ['name' => $owner->team->name]);

    $response->assertForbidden();
});

test('guests cannot reach the team settings page', function () {
    $this->get(route('team.edit'))->assertRedirect(route('login'));
});

/**
 * The database, not just the application, caps a user at one team (D-020).
 */
test('a second team membership for a user is refused', function () {
    $user = User::factory()->withTeam()->create();
    $other = Team::factory()->create();

    expect(fn () => app(CreateTeam::class)->handle($user, 'Second Business'))
        ->toThrow(RuntimeException::class, 'already belongs to a team');

    expect(Team::query()->where('name', 'Second Business')->exists())->toBeFalse();

    // With the application guard bypassed entirely — a raw insert the other
    // team's own context would happily admit — Postgres still refuses it.
    Teams::initialize($other);
    Teams::actingAs($user);

    expect(DB::table('team_user')->where('user_id', $user->id)->count())->toBe(1);

    // Last statement in the test: the violation aborts the surrounding
    // RefreshDatabase transaction, so nothing may be read after it.
    expect(fn () => DB::table('team_user')->insert([
        'team_id' => $other->id,
        'user_id' => $user->id,
        'role' => TeamRole::Agent->value,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'team_user_user_id_unique');
});
