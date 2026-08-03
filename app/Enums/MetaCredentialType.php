<?php

namespace App\Enums;

/**
 * The two-plus-one distinct Meta token types (docs/02-data-model.md §2).
 */
enum MetaCredentialType: string
{
    case Business = 'business';
    case Bisu = 'bisu';
    case System = 'system';
}
