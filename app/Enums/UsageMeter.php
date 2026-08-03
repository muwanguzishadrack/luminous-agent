<?php

namespace App\Enums;

/**
 * What a usage meter row measures (docs/02-data-model.md §11).
 */
enum UsageMeter: string
{
    case TemplateMessage = 'template_message';
    case ServiceMessage = 'service_message';
    case MbaTokens = 'mba_tokens';
    case PlatformSeat = 'platform_seat';
    case PaymentFee = 'payment_fee';
}
