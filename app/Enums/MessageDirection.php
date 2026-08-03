<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Message direction (docs/02-data-model.md §5).
 */
#[TypeScript]
enum MessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
