<?php

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Support\Facades\Tenancy;

test('expired invitations are deleted by the scheduled cleanup', function () {
    $this->travelTo(now()->startOfDay());

    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();

    Tenancy::initialize($tenant);
    $tenant->members()->attach($owner, ['role' => TenantRole::Owner->value]);

    $expiredInvitation = TenantInvitation::factory()->expired()->create([
        'tenant_id' => $tenant->id,
        'invited_by' => $owner->id,
    ]);

    $unexpiredInvitation = TenantInvitation::factory()->expiresIn(1)->create([
        'tenant_id' => $tenant->id,
        'invited_by' => $owner->id,
    ]);

    $invitationWithoutExpiration = TenantInvitation::factory()->create([
        'tenant_id' => $tenant->id,
        'invited_by' => $owner->id,
    ]);

    $this->artisan('schedule:run')->assertSuccessful();

    $this->assertDatabaseMissing('tenant_invitations', [
        'id' => $expiredInvitation->id,
    ]);

    $this->assertDatabaseHas('tenant_invitations', [
        'id' => $unexpiredInvitation->id,
    ]);

    $this->assertDatabaseHas('tenant_invitations', [
        'id' => $invitationWithoutExpiration->id,
    ]);
});
