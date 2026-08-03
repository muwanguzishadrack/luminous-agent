<?php

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Facades\Tenancy;

test('tenant member roles can be updated by owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);
    Tenancy::initialize($tenant);
    $tenant->members()->attach($member, ['role' => TenantRole::Agent->value]);

    $response = $this
        ->actingAs($owner)
        ->patch(route('tenants.members.update', [$tenant, $member]), [
            'role' => TenantRole::Admin->value,
        ]);

    $response->assertRedirect(route('tenants.edit', $tenant));

    expect($tenant->members()->where('user_id', $member->id)->first()->pivot->role->value)->toEqual(TenantRole::Admin->value);
});

test('tenant member roles cannot be updated by non owners', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);
    Tenancy::initialize($tenant);
    $tenant->members()->attach($admin, ['role' => TenantRole::Admin->value]);
    Tenancy::initialize($tenant);
    $tenant->members()->attach($member, ['role' => TenantRole::Agent->value]);

    $response = $this
        ->actingAs($admin)
        ->patch(route('tenants.members.update', [$tenant, $member]), [
            'role' => TenantRole::Admin->value,
        ]);

    $response->assertForbidden();
});

test('tenant members can be removed by owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);
    Tenancy::initialize($tenant);
    $tenant->members()->attach($member, ['role' => TenantRole::Agent->value]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('tenants.members.destroy', [$tenant, $member]));

    $response->assertRedirect(route('tenants.edit', $tenant));

    expect($member->fresh()->belongsToTenant($tenant))->toBeFalse();
});

test('tenant members cannot be removed by non owners', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);
    Tenancy::initialize($tenant);
    $tenant->members()->attach($admin, ['role' => TenantRole::Admin->value]);
    Tenancy::initialize($tenant);
    $tenant->members()->attach($member, ['role' => TenantRole::Agent->value]);

    $response = $this
        ->actingAs($admin)
        ->delete(route('tenants.members.destroy', [$tenant, $member]));

    $response->assertForbidden();
});

test('tenant owner cannot be removed', function () {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('tenants.members.destroy', [$tenant, $owner]));

    $response->assertForbidden();

    expect($owner->fresh()->belongsToTenant($tenant))->toBeTrue();
});

test('tenant member role cannot be set to owner', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);
    Tenancy::initialize($tenant);
    $tenant->members()->attach($member, ['role' => TenantRole::Agent->value]);

    $response = $this
        ->actingAs($owner)
        ->patch(route('tenants.members.update', [$tenant, $member]), [
            'role' => TenantRole::Owner->value,
        ]);

    $response->assertSessionHasErrors('role');

    expect($tenant->members()->where('user_id', $member->id)->first()->pivot->role->value)->toEqual(TenantRole::Agent->value);
});

test('removed member current tenant is set to personal tenant', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $personalTenant = $member->personalTenant();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);
    Tenancy::initialize($tenant);
    $tenant->members()->attach($member, ['role' => TenantRole::Agent->value]);

    $member->update(['current_tenant_id' => $tenant->id]);

    $this
        ->actingAs($owner)
        ->delete(route('tenants.members.destroy', [$tenant, $member]));

    expect($member->fresh()->current_tenant_id)->toEqual($personalTenant->id);
});
