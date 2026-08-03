<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Consent state (docs/02-data-model.md §4).
 */
#[TypeScript]
enum ConsentState: string
{
    case Granted = 'granted';
    case Revoked = 'revoked';
}
