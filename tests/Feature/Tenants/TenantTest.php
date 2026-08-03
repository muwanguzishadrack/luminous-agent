<?php

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Facades\Tenancy;
use Inertia\Testing\AssertableInertia as Assert;

test('the tenants index page can be rendered', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('tenants.index'));

    $response->assertOk();
});

test('tenants can be created', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('tenants.store'), [
            'name' => 'Test Tenant',
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('tenants', [
        'name' => 'Test Tenant',
        'is_personal' => false,
    ]);
});

test('tenant slug uses next available suffix', function () {
    $user = User::factory()->create();

    Tenant::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    Tenant::factory()->create(['name' => 'Acme One', 'slug' => 'acme-1']);
    Tenant::factory()->create(['name' => 'Acme Ten', 'slug' => 'acme-10']);

    $this
        ->actingAs($user)
        ->post(route('tenants.store'), [
            'name' => 'Acme',
        ]);

    $this->assertDatabaseHas('tenants', [
        'name' => 'Acme',
        'slug' => 'acme-11',
    ]);
});

test('the tenant edit page can be rendered', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($user, ['role' => TenantRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->get(route('tenants.edit', $tenant));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tenants/edit')
            ->where('members.0.role', TenantRole::Owner->value)
            ->where('members.0.role_label', TenantRole::Owner->label()),
        );
});

test('tenants can be updated by owners', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create(['name' => 'Original Name']);

    Tenancy::initialize($tenant);
    $tenant->members()->attach($user, ['role' => TenantRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->patch(route('tenants.update', $tenant), [
            'name' => 'Updated Name',
        ]);

    $response->assertRedirect(route('tenants.edit', $tenant->fresh()));

    $this->assertDatabaseHas('tenants', [
        'id' => $tenant->id,
        'name' => 'Updated Name',
    ]);
});

test('tenants cannot be updated by members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);
    Tenancy::initialize($tenant);
    $tenant->members()->attach($member, ['role' => TenantRole::Agent->value]);

    $response = $this
        ->actingAs($member)
        ->patch(route('tenants.update', $tenant), [
            'name' => 'Updated Name',
        ]);

    $response->assertForbidden();
});

test('tenants can be deleted by owners', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($user, ['role' => TenantRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->delete(route('tenants.destroy', $tenant), [
            'name' => $tenant->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('tenants', [
        'id' => $tenant->id,
    ]);
});

test('tenant deletion requires name confirmation', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($user, ['role' => TenantRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->delete(route('tenants.destroy', $tenant), [
            'name' => 'Wrong Name',
        ]);

    $response->assertSessionHasErrors('name');

    $this->assertDatabaseHas('tenants', [
        'id' => $tenant->id,
        'deleted_at' => null,
    ]);
});

test('deleting current tenant switches to alphabetically first remaining tenant', function () {
    $user = User::factory()->create(['name' => 'Mike']);

    $zuluTenant = Tenant::factory()->create(['name' => 'Zulu Tenant']);
    Tenancy::initialize($zuluTenant);
    $zuluTenant->members()->attach($user, ['role' => TenantRole::Owner->value]);

    $alphaTenant = Tenant::factory()->create(['name' => 'Alpha Tenant']);
    Tenancy::initialize($alphaTenant);
    $alphaTenant->members()->attach($user, ['role' => TenantRole::Owner->value]);

    $betaTenant = Tenant::factory()->create(['name' => 'Beta Tenant']);
    Tenancy::initialize($betaTenant);
    $betaTenant->members()->attach($user, ['role' => TenantRole::Owner->value]);

    $user->update(['current_tenant_id' => $zuluTenant->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('tenants.destroy', $zuluTenant), [
            'name' => $zuluTenant->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('tenants', [
        'id' => $zuluTenant->id,
    ]);

    expect($user->fresh()->current_tenant_id)->toEqual($alphaTenant->id);
});

test('deleting current tenant falls back to personal tenant when alphabetically first', function () {
    $user = User::factory()->create();
    $personalTenant = $user->personalTenant();
    $tenant = Tenant::factory()->create(['name' => 'Zulu Tenant']);
    Tenancy::initialize($tenant);
    $tenant->members()->attach($user, ['role' => TenantRole::Owner->value]);

    $user->update(['current_tenant_id' => $tenant->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('tenants.destroy', $tenant), [
            'name' => $tenant->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('tenants', [
        'id' => $tenant->id,
    ]);

    expect($user->fresh()->current_tenant_id)->toEqual($personalTenant->id);
});

test('deleting non current tenant leaves current tenant unchanged', function () {
    $user = User::factory()->create();
    $personalTenant = $user->personalTenant();
    $tenant = Tenant::factory()->create();
    Tenancy::initialize($tenant);
    $tenant->members()->attach($user, ['role' => TenantRole::Owner->value]);

    $user->update(['current_tenant_id' => $personalTenant->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('tenants.destroy', $tenant), [
            'name' => $tenant->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('tenants', [
        'id' => $tenant->id,
    ]);

    expect($user->fresh()->current_tenant_id)->toEqual($personalTenant->id);
});

test('members can leave non personal tenants', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);
    Tenancy::initialize($tenant);
    $tenant->members()->attach($member, ['role' => TenantRole::Agent->value]);

    $response = $this
        ->actingAs($member)
        ->delete(route('tenants.leave', $tenant));

    $response->assertRedirect(route('tenants.index'));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => "You left the tenant \"{$tenant->name}\""]);

    expect($member->fresh()->belongsToTenant($tenant))->toBeFalse();
});

test('leaving current tenant switches to alphabetically first remaining tenant', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => 'Mike']);

    $zuluTenant = Tenant::factory()->create(['name' => 'Zulu Tenant']);
    Tenancy::initialize($zuluTenant);
    $zuluTenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);
    Tenancy::initialize($zuluTenant);
    $zuluTenant->members()->attach($member, ['role' => TenantRole::Agent->value]);

    $alphaTenant = Tenant::factory()->create(['name' => 'Alpha Tenant']);
    Tenancy::initialize($alphaTenant);
    $alphaTenant->members()->attach($member, ['role' => TenantRole::Agent->value]);

    $betaTenant = Tenant::factory()->create(['name' => 'Beta Tenant']);
    Tenancy::initialize($betaTenant);
    $betaTenant->members()->attach($member, ['role' => TenantRole::Agent->value]);

    $member->update(['current_tenant_id' => $zuluTenant->id]);

    $response = $this
        ->actingAs($member)
        ->delete(route('tenants.leave', $zuluTenant));

    $response->assertRedirect(route('tenants.index'));

    expect($member->fresh()->belongsToTenant($zuluTenant))->toBeFalse();
    expect($member->fresh()->current_tenant_id)->toEqual($alphaTenant->id);
});

test('personal tenants cannot be left', function () {
    $user = User::factory()->create();
    $personalTenant = $user->personalTenant();

    $response = $this
        ->actingAs($user)
        ->delete(route('tenants.leave', $personalTenant));

    $response->assertForbidden();

    expect($user->fresh()->belongsToTenant($personalTenant))->toBeTrue();
});

test('tenant owners cannot leave their tenant', function () {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('tenants.leave', $tenant));

    $response->assertForbidden();

    expect($owner->fresh()->belongsToTenant($tenant))->toBeTrue();
});

test('users cannot leave tenants they dont belong to', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('tenants.leave', $tenant));

    $response->assertForbidden();
});

test('deleting tenant switches other affected users to their personal tenant', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $tenant = Tenant::factory()->create();
    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);
    Tenancy::initialize($tenant);
    $tenant->members()->attach($member, ['role' => TenantRole::Agent->value]);

    $owner->update(['current_tenant_id' => $tenant->id]);
    $member->update(['current_tenant_id' => $tenant->id]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('tenants.destroy', $tenant), [
            'name' => $tenant->name,
        ]);

    $response->assertRedirect();

    expect($member->fresh()->current_tenant_id)->toEqual($member->personalTenant()->id);
});

test('personal tenants cannot be deleted', function () {
    $user = User::factory()->create();

    $personalTenant = $user->personalTenant();

    $response = $this
        ->actingAs($user)
        ->delete(route('tenants.destroy', $personalTenant), [
            'name' => $personalTenant->name,
        ]);

    $response->assertForbidden();

    $this->assertDatabaseHas('tenants', [
        'id' => $personalTenant->id,
        'deleted_at' => null,
    ]);
});

test('tenants cannot be deleted by non owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);
    Tenancy::initialize($tenant);
    $tenant->members()->attach($member, ['role' => TenantRole::Agent->value]);

    $response = $this
        ->actingAs($member)
        ->delete(route('tenants.destroy', $tenant), [
            'name' => $tenant->name,
        ]);

    $response->assertForbidden();
});

test('users can switch tenants', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($user, ['role' => TenantRole::Agent->value]);

    $response = $this
        ->actingAs($user)
        ->post(route('tenants.switch', $tenant));

    $response->assertRedirect();

    expect($user->fresh()->current_tenant_id)->toEqual($tenant->id);
});

test('users cannot switch to tenant they dont belong to', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('tenants.switch', $tenant));

    $response->assertForbidden();
});

test('guests cannot access tenants', function () {
    $response = $this->get(route('tenants.index'));

    $response->assertRedirect(route('login'));
});
