<?php

namespace App\Actions\Tenants;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Facades\Tenancy;
use Illuminate\Support\Facades\DB;

class CreateTenant
{
    /**
     * Create a new tenant and add the user as owner.
     */
    public function handle(User $user, string $name, bool $isPersonal = false): Tenant
    {
        return DB::transaction(function () use ($user, $name, $isPersonal) {
            $tenant = Tenant::create([
                'name' => $name,
                'is_personal' => $isPersonal,
            ]);

            // The tenant did not exist a moment ago — establish its context
            // before the first tenant-scoped write (RLS enforces this).
            Tenancy::initialize($tenant);

            $membership = $tenant->memberships()->create([
                'user_id' => $user->id,
                'role' => TenantRole::Owner,
            ]);

            $user->switchTenant($tenant);

            return $tenant;
        });
    }
}
