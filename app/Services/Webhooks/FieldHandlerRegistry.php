<?php

namespace App\Services\Webhooks;

use App\Services\Webhooks\Handlers\HandleAccountUpdate;
use App\Services\Webhooks\Handlers\HandlePaymentConfigurationUpdate;

/**
 * Maps webhook fields to their handlers. Fields without a handler are parked
 * (recorded, never dropped) until their module lands:
 * messages/statuses/standby/messaging_handovers → M1, user_preferences → M2,
 * template events → M3, coexistence fields → Phase 4.
 */
class FieldHandlerRegistry
{
    /** @var array<string, class-string<FieldHandler>> */
    private const HANDLERS = [
        'account_update' => HandleAccountUpdate::class,
        'payment_configuration_update' => HandlePaymentConfigurationUpdate::class,
    ];

    public function for(string $field): ?FieldHandler
    {
        $class = self::HANDLERS[$field] ?? null;

        return $class === null ? null : app($class);
    }
}
