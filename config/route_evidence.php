<?php

return [
    'estimates_enabled' => env('ROUTE_EVIDENCE_ESTIMATES_ENABLED', false),
    'policy_version' => env('ROUTE_EVIDENCE_POLICY_VERSION', 'operational-v1'),
    'osrm' => [
        'enabled' => env('ROUTE_EVIDENCE_OSRM_ENABLED', true),
        'base_url' => env('ROUTE_EVIDENCE_OSRM_URL', 'https://router.project-osrm.org'),
        'timeout_seconds' => 8,
    ],
    'google' => [
        'enabled' => env('ROUTE_EVIDENCE_GOOGLE_ENABLED', false),
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
        'daily_budget' => env('ROUTE_EVIDENCE_GOOGLE_DAILY_BUDGET', 0),
        'monthly_budget' => env('ROUTE_EVIDENCE_GOOGLE_MONTHLY_BUDGET', 0),
        'failure_threshold' => 3,
        'circuit_minutes' => 15,
        'timeout_seconds' => 8,
    ],
];
