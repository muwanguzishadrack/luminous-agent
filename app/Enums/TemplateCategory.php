<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Template category — Meta's three stable categories (docs/02-data-model.md §6).
 */
#[TypeScript]
enum TemplateCategory: string
{
    case Marketing = 'MARKETING';
    case Utility = 'UTILITY';
    case Authentication = 'AUTHENTICATION';
}
