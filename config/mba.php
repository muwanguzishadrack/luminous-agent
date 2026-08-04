<?php

// Facts: docs/reference/meta-business-agent.md.
return [
    // Prefix only — the per-team connector URL is "{base}/{team}" and the
    // {team} segment must match the connector token (docs/05 §3).
    'connector_base_url' => env('MBA_CONNECTOR_BASE_URL'),
    'connector_token_ttl_days' => 90,

    // The five verticals MBA supports (reference §2).
    'verticals' => [
        'automotive',
        'consumer_packaged_goods',
        'professional_services',
        'retail_ecommerce',
        'travel',
    ],
];
