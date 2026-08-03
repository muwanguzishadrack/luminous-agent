<?php

namespace App\Services\Webhooks\Handlers;

use App\Models\Tenant;
use App\Models\WabaAccount;
use App\Services\Webhooks\FieldHandler;
use App\Support\AuditLog;

/**
 * Payment-readiness gate: as a Tech Provider the client attaches their own
 * payment method; this webhook is how we learn its state
 * (docs/modules/m0-onboarding.md §4).
 */
class HandlePaymentConfigurationUpdate implements FieldHandler
{
    public function handle(Tenant $tenant, array $value, array $entry): void
    {
        $waba = WabaAccount::query()
            ->where('waba_id', (string) ($entry['id'] ?? ''))
            ->first();

        if ($waba === null) {
            return;
        }

        // Meta's payload carries a configuration_name + status; anything other
        // than an effective/verified status keeps the gate closed.
        $status = strtoupper((string) ($value['payment_configuration']['status'] ?? $value['status'] ?? ''));

        $waba->forceFill([
            'payment_ready' => in_array($status, ['EFFECTIVE', 'ACTIVE', 'VERIFIED'], true),
        ])->save();

        AuditLog::record(
            action: 'waba.payment_configuration_update',
            subject: $waba,
            context: ['status' => $status, 'value' => $value],
        );
    }
}
