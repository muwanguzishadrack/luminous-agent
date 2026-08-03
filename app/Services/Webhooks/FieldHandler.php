<?php

namespace App\Services\Webhooks;

use App\Models\Tenant;

/**
 * One handler per webhook field (docs/reference/whatsapp-webhooks.md §2).
 * Handlers run under established tenant context and must be idempotent —
 * Meta redelivers, and webhook:replay re-runs everything.
 */
interface FieldHandler
{
    /**
     * @param  array<string, mixed>  $value  the change's `value` object
     * @param  array<string, mixed>  $entry  the enclosing entry (waba id, time)
     */
    public function handle(Tenant $tenant, array $value, array $entry): void;
}
