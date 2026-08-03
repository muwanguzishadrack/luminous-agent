<?php

namespace App\Enums;

/**
 * Media malware-scan status (docs/02-data-model.md §5).
 */
enum MediaScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';
    case Skipped = 'skipped';
}
