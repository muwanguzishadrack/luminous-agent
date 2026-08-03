<?php

namespace App\Enums;

/**
 * Basis of a usage meter row — rows are never re-marked in place, D-012
 * (docs/02-data-model.md §11).
 */
enum UsageBasis: string
{
    case Estimate = 'estimate';
    case Actual = 'actual';
    case Correction = 'correction';
}
