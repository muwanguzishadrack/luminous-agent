<?php

// Every rate limit in one place so they are testable and tunable
// (docs/reference/pricing-and-limits.md §3/§6). Never inline these in a class.
return [
    'graph' => [
        'waba_requests_per_hour' => 5_000, // Business Management endpoints, active WABA
        'waba_requests_per_hour_idle' => 200,
    ],

    'send' => [
        // Per-number message throughput (mps). Governs campaign send rate.
        'throughput_mps_standard' => 80,
        'throughput_mps_high' => 1_000,
        'throughput_mps_coexistence' => 20,
        // Our own safety ceiling as a fraction of Meta's limit.
        'throughput_headroom' => 0.8,

        // Per-recipient pair limit: 1 msg / 6s to the same wa_id.
        'per_recipient_seconds' => 6,
        'pair_burst_messages' => 45, // per recipient pair, not global
        'pair_burst_window_seconds' => 6,
        'retry_base' => 4, // retry after 4^X seconds
    ],

    'connector' => [
        'requests_per_minute' => 120,
    ],

    'iotec' => [
        'token_ttl' => 240, // provider TTL is 300s; refresh at 240 with single-flight
        'min_amount_major' => 500,
    ],
];
