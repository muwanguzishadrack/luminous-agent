<?php

namespace App\Enums;

/**
 * Webhook delivery source (docs/02-data-model.md §3).
 */
enum WebhookSource: string
{
    case Meta = 'meta';
    case Iotec = 'iotec';
}
