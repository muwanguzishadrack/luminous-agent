<?php

namespace App\Enums;

/**
 * How a campaign is delivered (docs/02-data-model.md §7).
 */
enum CampaignRouting: string
{
    case CloudApi = 'cloud_api';
    case MmApi = 'mm_api';
}
