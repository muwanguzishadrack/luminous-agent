<?php

// Facts: docs/reference/iotec-pay.md.
return [
    'token_url' => env('IOTEC_TOKEN_URL', 'https://id.iotec.io/connect/token'),
    'base_url' => env('IOTEC_BASE_URL', 'https://pay.iotec.io'),
    'client_id' => env('IOTEC_CLIENT_ID'),
    'client_secret' => env('IOTEC_CLIENT_SECRET'),

    'wallets' => [
        'UGX' => env('IOTEC_WALLET_ID_UGX'),
        'ITX' => env('IOTEC_WALLET_ID_ITX'), // sandbox currency
    ],

    'default_currency' => env('IOTEC_DEFAULT_CURRENCY', 'UGX'),

    // Static per-wallet callback header (weak — always re-fetch status before
    // crediting, D-010).
    'callback_header_name' => env('IOTEC_CALLBACK_HEADER_NAME', 'X-Iotec-Signature'),
    'callback_header_value' => env('IOTEC_CALLBACK_HEADER_VALUE'),
];
