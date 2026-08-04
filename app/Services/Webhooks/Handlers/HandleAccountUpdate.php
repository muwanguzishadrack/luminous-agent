<?php

namespace App\Services\Webhooks\Handlers;

use App\Models\Team;
use App\Models\WabaAccount;
use App\Services\Webhooks\FieldHandler;
use App\Support\AuditLog;

/**
 * Team lifecycle from account_update events
 * (docs/reference/whatsapp-webhooks.md §8, docs/modules/m0-onboarding.md §5).
 */
class HandleAccountUpdate implements FieldHandler
{
    public function handle(Team $team, array $value, array $entry): void
    {
        $event = (string) ($value['event'] ?? '');
        $wabaId = (string) ($value['waba_info']['waba_id'] ?? $entry['id'] ?? '');

        $waba = WabaAccount::query()->where('waba_id', $wabaId)->first();

        match ($event) {
            'PARTNER_ADDED', 'PARTNER_APP_INSTALLED' => $this->reactivate($team, $waba, $value),
            'PARTNER_REMOVED' => $this->suspend($team, $waba, $value),
            default => $this->recordAccountChange($waba, $value),
        };

        AuditLog::record(
            action: 'waba.account_update',
            subject: $waba,
            context: ['event' => $event, 'value' => $value],
        );
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function reactivate(Team $team, ?WabaAccount $waba, array $value): void
    {
        if ($team->status === 'suspended') {
            $team->forceFill(['status' => 'active', 'suspended_reason' => null])->save();
        }

        $waba?->forceFill([
            'account_status' => 'ACTIVE',
            'is_subscribed' => true,
            'solution_id' => $value['waba_info']['solution_id'] ?? $waba->solution_id,
        ])->save();
    }

    /**
     * Client revoked our app: suspend, keep data per retention policy
     * (docs/modules/m0-onboarding.md §5).
     *
     * @param  array<string, mixed>  $value
     */
    private function suspend(Team $team, ?WabaAccount $waba, array $value): void
    {
        $team->forceFill([
            'status' => 'suspended',
            'suspended_reason' => 'partner_removed',
        ])->save();

        $waba?->forceFill([
            'account_status' => 'PARTNER_REMOVED',
            'is_subscribed' => false,
            'offboarded_at' => now(),
        ])->save();
    }

    /**
     * Verification / capability changes: keep the WABA row current
     * (docs/reference/whatsapp-webhooks.md §8).
     *
     * @param  array<string, mixed>  $value
     */
    private function recordAccountChange(?WabaAccount $waba, array $value): void
    {
        if ($waba === null) {
            return;
        }

        $waba->forceFill(array_filter([
            'account_status' => $value['event'] ?? null,
            'business_verification_status' => $value['waba_info']['business_verification_status'] ?? null,
        ], fn ($v) => $v !== null))->save();
    }
}
