<?php

namespace App\Support;

use App\Exceptions\MissingTenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Holds the active tenant context for the current request / job and mirrors it
 * into the Postgres session (`app.tenant_id`, `app.user_id`) so Row Level
 * Security enforces the same boundary at the database layer
 * (docs/05-security-multitenancy.md §1).
 *
 * Registered as a scoped singleton — Octane resets it per request. Queued jobs
 * must re-establish context in handle() (docs/05 §1, "dangerous paths").
 */
class TenancyManager
{
    private ?Tenant $tenant = null;

    /**
     * Establish tenant context for this request/job.
     */
    public function initialize(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $tenant->id]);
    }

    /**
     * Identify the authenticated user to the database session. Required by the
     * user-aware RLS policy on tenant_user (membership listing pre-context).
     */
    public function actingAs(?User $user): void
    {
        DB::statement("SELECT set_config('app.user_id', ?, false)", [(string) $user?->id]);
    }

    /**
     * Clear all tenant context, including the database session variables.
     */
    public function forget(): void
    {
        $this->tenant = null;

        DB::statement("SELECT set_config('app.tenant_id', '', false)");
    }

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    public function currentId(): ?string
    {
        return $this->tenant?->id;
    }

    /**
     * @throws MissingTenantContext when no tenant context is established.
     */
    public function currentOrFail(): Tenant
    {
        return $this->tenant ?? throw new MissingTenantContext;
    }

    /**
     * @throws MissingTenantContext when no tenant context is established —
     *                              there is deliberately no silent default (docs/05 §1 layer 1).
     */
    public function currentIdOrFail(): string
    {
        return $this->currentId() ?? throw new MissingTenantContext;
    }

    public function initialized(): bool
    {
        return $this->tenant !== null;
    }
}
