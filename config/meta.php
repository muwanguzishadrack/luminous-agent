<?php

// Facts: docs/reference/whatsapp-cloud-api.md. Pin the Graph version (D-016/D-017);
// never call an unversioned Graph URL.
return [
    'graph_version' => env('META_GRAPH_VERSION', 'v26.0'),
    'graph_base_url' => 'https://graph.facebook.com',

    'app_id' => env('META_APP_ID'),
    'app_secret' => env('META_APP_SECRET'),
    'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),

    // Embedded Signup v4 configuration id (docs/modules/m0-onboarding.md §1).
    'es_config_id' => env('META_ES_CONFIG_ID'),
    'solution_id' => env('META_SOLUTION_ID'),
    'system_token' => env('META_SYSTEM_TOKEN'),

    // Tech Provider: business tokens only (reference §1).
    'permissions' => [
        'whatsapp_business_management',
        'whatsapp_business_messaging',
    ],
];
