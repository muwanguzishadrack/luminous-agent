<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Consent scope (docs/02-data-model.md §4).
 */
#[TypeScript]
enum ConsentScope: string
{
    case Marketing = 'marketing';
    case Utility = 'utility';
    case Authentication = 'authentication';
    case All = 'all';
}
