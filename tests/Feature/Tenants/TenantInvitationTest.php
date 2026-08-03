<?php

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Notifications\Tenants\TenantInvitation as TenantInvitationNotification;
use App\Support\Facades\Tenancy;
use Illuminate\Support\Facades\Notification;

test('tenant invitations can be created', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);

    $response = $this
        ->actingAs($owner)
        ->post(route('tenants.invitations.store', $tenant), [
            'email' => 'invited@example.com',
            'role' => TenantRole::Agent->value,
        ]);

    $response->assertRedirect(route('tenants.edit', $tenant));

    $this->assertDatabaseHas('tenant_invitations', [
        'tenant_id' => $tenant->id,
        'email' => 'invited@example.com',
        'role' => TenantRole::Agent->value,
    ]);
});

test('invitation email for existing users uses login route', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);

    $invitation = TenantInvitation::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => $invitedUser->email,
        'invited_by' => $owner->id,
    ]);

    $mail = (new TenantInvitationNotification($invitation))->toMail($invitedUser);

    expect($mail->actionUrl)->toBe(route('login', ['invitation' => $invitation->code]));
    $this->assertStringContainsString('dashboard', implode(' ', $mail->introLines));
});

test('invitation email for unknown users uses login route', function () {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);

    $invitation = TenantInvitation::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'unknown@example.com',
        'invited_by' => $owner->id,
    ]);

    $mail = (new TenantInvitationNotification($invitation))->toMail((object) []);

    expect($mail->actionUrl)->toBe(route('login', ['invitation' => $invitation->code]));
    $this->assertStringContainsString('log in', strtolower(implode(' ', $mail->introLines)));
});

test('tenant invitations can be created by admins', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);
    Tenancy::initialize($tenant);
    $tenant->members()->attach($admin, ['role' => TenantRole::Admin->value]);

    $response = $this
        ->actingAs($admin)
        ->post(route('tenants.invitations.store', $tenant), [
            'email' => 'invited@example.com',
            'role' => TenantRole::Agent->value,
        ]);

    $response->assertRedirect(route('tenants.edit', $tenant));
});

test('existing tenant members cannot be invited', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $member = User::factory()->create(['email' => 'member@example.com']);
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);
    Tenancy::initialize($tenant);
    $tenant->members()->attach($member, ['role' => TenantRole::Agent->value]);

    $response = $this
        ->actingAs($owner)
        ->post(route('tenants.invitations.store', $tenant), [
            'email' => 'member@example.com',
            'role' => TenantRole::Agent->value,
        ]);

    $response->assertSessionHasErrors('email');
});

test('duplicate invitations cannot be created', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);

    TenantInvitation::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($owner)
        ->post(route('tenants.invitations.store', $tenant), [
            'email' => 'invited@example.com',
            'role' => TenantRole::Agent->value,
        ]);

    $response->assertSessionHasErrors('email');
});

test('tenant invitations cannot be created by members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);
    Tenancy::initialize($tenant);
    $tenant->members()->attach($member, ['role' => TenantRole::Agent->value]);

    $response = $this
        ->actingAs($member)
        ->post(route('tenants.invitations.store', $tenant), [
            'email' => 'invited@example.com',
            'role' => TenantRole::Agent->value,
        ]);

    $response->assertForbidden();
});

test('tenant invitations can be cancelled by owners', function () {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);

    $invitation = TenantInvitation::factory()->create([
        'tenant_id' => $tenant->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('tenants.invitations.destroy', [$tenant, $invitation]));

    $response->assertRedirect(route('tenants.edit', $tenant));

    $this->assertDatabaseMissing('tenant_invitations', [
        'id' => $invitation->id,
    ]);
});

test('tenant invitations can be accepted', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);

    $invitation = TenantInvitation::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'invited@example.com',
        'role' => TenantRole::Agent,
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('invitations.accept', $invitation));

    $response->assertRedirect(route('dashboard'));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Invitation accepted.']);

    expect($invitedUser->fresh()->belongsToTenant($tenant))->toBeTrue();
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('tenant invitations can be declined by the invited user', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);

    $invitation = TenantInvitation::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertRedirect(route('dashboard'));

    $this->assertDatabaseMissing('tenant_invitations', [
        'id' => $invitation->id,
    ]);
});

test('tenant invitations cannot be declined by uninvited user', function () {
    $owner = User::factory()->create();
    $uninvitedUser = User::factory()->create(['email' => 'uninvited@example.com']);
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);

    $invitation = TenantInvitation::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($uninvitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertSessionHasErrors('invitation');

    $this->assertDatabaseHas('tenant_invitations', [
        'id' => $invitation->id,
    ]);
});

test('accepted tenant invitations cannot be declined', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);

    $invitation = TenantInvitation::factory()->accepted()->create([
        'tenant_id' => $tenant->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertSessionHasErrors('invitation');

    $this->assertDatabaseHas('tenant_invitations', [
        'id' => $invitation->id,
    ]);
});

test('tenant invitations cannot be accepted by uninvited user', function () {
    $owner = User::factory()->create();
    $uninvitedUser = User::factory()->create(['email' => 'uninvited@example.com']);
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);

    $invitation = TenantInvitation::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($uninvitedUser)
        ->get(route('invitations.accept', $invitation));

    $response->assertSessionHasErrors('invitation');

    expect($uninvitedUser->fresh()->belongsToTenant($tenant))->toBeFalse();
});

test('expired invitations cannot be accepted', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);

    $invitation = TenantInvitation::factory()->expired()->create([
        'tenant_id' => $tenant->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('invitations.accept', $invitation));

    $response->assertSessionHasErrors('invitation');

    expect($invitedUser->fresh()->belongsToTenant($tenant))->toBeFalse();
});
