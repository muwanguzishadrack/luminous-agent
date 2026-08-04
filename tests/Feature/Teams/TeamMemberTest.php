<?php

use App\Enums\TeamRole;
use App\Models\User;
use App\Support\Facades\Teams;

test('team member roles can be updated by owners', function () {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->team;
    $member = User::factory()->memberOf($team, TeamRole::Agent)->create();

    $response = $this
        ->actingAs($owner)
        ->patch(route('team.members.update', $member), [
            'role' => TeamRole::Admin->value,
        ]);

    $response->assertRedirect(route('team.edit'));

    expect($member->fresh()->teamRole($team))->toBe(TeamRole::Admin);
});

test('team member roles cannot be updated by non owners', function () {
    $owner = User::factory()->withTeam()->create();
    $admin = User::factory()->memberOf($owner->team, TeamRole::Admin)->create();
    $member = User::factory()->memberOf($owner->team, TeamRole::Agent)->create();

    $response = $this
        ->actingAs($admin)
        ->patch(route('team.members.update', $member), [
            'role' => TeamRole::Admin->value,
        ]);

    $response->assertForbidden();
});

test('team members can be removed by owners', function () {
    $owner = User::factory()->withTeam()->create();
    $member = User::factory()->memberOf($owner->team, TeamRole::Agent)->create();

    $response = $this
        ->actingAs($owner)
        ->delete(route('team.members.destroy', $member));

    $response->assertRedirect(route('team.edit'));

    // Removal leaves them without a team — there is no personal team to fall
    // back to any more (D-020).
    expect($member->fresh()->belongsToAnyTeam())->toBeFalse();
});

test('team members cannot be removed by non owners', function () {
    $owner = User::factory()->withTeam()->create();
    $admin = User::factory()->memberOf($owner->team, TeamRole::Admin)->create();
    $member = User::factory()->memberOf($owner->team, TeamRole::Agent)->create();

    $response = $this
        ->actingAs($admin)
        ->delete(route('team.members.destroy', $member));

    $response->assertForbidden();
});

test('team owner cannot be removed', function () {
    $owner = User::factory()->withTeam()->create();

    $response = $this
        ->actingAs($owner)
        ->delete(route('team.members.destroy', $owner));

    $response->assertForbidden();

    expect($owner->fresh()->belongsToTeam($owner->team))->toBeTrue();
});

test('team member role cannot be set to owner', function () {
    $owner = User::factory()->withTeam()->create();
    $member = User::factory()->memberOf($owner->team, TeamRole::Agent)->create();

    $response = $this
        ->actingAs($owner)
        ->patch(route('team.members.update', $member), [
            'role' => TeamRole::Owner->value,
        ]);

    $response->assertSessionHasErrors('role');

    expect($member->fresh()->teamRole($owner->team))->toBe(TeamRole::Agent);
});

test('a member of another team cannot be touched through this team', function () {
    $owner = User::factory()->withTeam()->create();
    $stranger = User::factory()->withTeam()->create();
    $strangerTeam = $stranger->team;

    $this->actingAs($owner)
        ->delete(route('team.members.destroy', $stranger))
        ->assertRedirect(route('team.edit'));

    // Nothing happened: the delete was scoped to the acting team's
    // memberships. Read it back as the stranger — their own row is only
    // visible to them or to their team (docs/05 §1 layer 2).
    Teams::initialize($strangerTeam);
    Teams::actingAs($stranger);

    expect($stranger->fresh()->belongsToTeam($strangerTeam))->toBeTrue();
});
