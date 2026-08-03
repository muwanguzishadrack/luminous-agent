<?php

// Facts: docs/reference/pricing-and-limits.md §1/§2.
return [
    'mba' => [
        'usd_per_million_tokens' => 2.00,
        'est_tokens_per_message' => 22_500, // midpoint of Meta's stated 20k–25k
    ],

    'dates' => [
        'mba_charging_from' => '2026-08-01',
        'all_non_template_charged' => '2026-10-01',
        'es_v2_removed' => '2026-10-15',
    ],
];
