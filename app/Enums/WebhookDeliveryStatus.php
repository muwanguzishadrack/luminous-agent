<?php

namespace App\Enums;

/**
 * Webhook delivery processing status (docs/02-data-model.md §3).
 */
enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Partial = 'partial';
    case Failed = 'failed';
    case Ignored = 'ignored';
}
