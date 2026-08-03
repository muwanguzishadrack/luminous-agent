<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Who produced a message (docs/02-data-model.md §5).
 */
#[TypeScript]
enum MessageOrigin: string
{
    case Agent = 'agent';
    case Mba = 'mba';
    case Campaign = 'campaign';
    case Automation = 'automation';
    case OwnerDevice = 'owner_device';
    case Customer = 'customer';
    case System = 'system';
}
