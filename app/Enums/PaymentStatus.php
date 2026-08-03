<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The nine ioTec transaction statuses, verbatim (docs/02-data-model.md §9).
 */
#[TypeScript]
enum PaymentStatus: string
{
    case Pending = 'Pending';
    case SentToVendor = 'SentToVendor';
    case Success = 'Success';
    case Failed = 'Failed';
    case AwaitingApproval = 'AwaitingApproval';
    case RolledBack = 'RolledBack';
    case Scheduled = 'Scheduled';
    case Cancelled = 'Cancelled';
    case Rejected = 'Rejected';
}
