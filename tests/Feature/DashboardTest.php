<?php

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Support\Facades\Tenancy;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();
    $tenant = $user->currentTenant;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $tenant = $user->currentTenant;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('dashboard includes pending invitations for the authenticated user', function () {
    $owner = User::factory()->create(['name' => 'Taylor Otwell']);
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $tenant = Tenant::factory()->create(['name' => 'Laravel Tenant']);

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);

    $invitation = TenantInvitation::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 1)
        ->where('pendingInvitations.0.code', $invitation->code)
        ->where('pendingInvitations.0.inviterName', 'Taylor Otwell')
        ->where('pendingInvitations.0.tenant.name', 'Laravel Tenant')
        ->where('pendingInvitations.0.tenant.slug', $tenant->slug)
        ->missing('pendingInvitations.0.tenantName'),
    );
});

test('dashboard does not include accepted invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);

    TenantInvitation::factory()->accepted()->create([
        'tenant_id' => $tenant->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );
});

test('dashboard excludes expired invitations without deleting them', function () {
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
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('tenant_invitations', [
        'id' => $invitation->id,
    ]);
});

test('dashboard does not include or delete other users invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);

    $invitation = TenantInvitation::factory()->expired()->create([
        'tenant_id' => $tenant->id,
        'email' => 'someone@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('tenant_invitations', [
        'id' => $invitation->id,
    ]);
});
