<?php

namespace App\Enums;

/**
 * Payment direction (docs/02-data-model.md §9).
 */
enum PaymentDirection: string
{
    case Collection = 'collection';
    case Disbursement = 'disbursement';
}
