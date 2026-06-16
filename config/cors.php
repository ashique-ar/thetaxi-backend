<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Restrict API access to known frontend origins only.
    | Wildcard '*' is intentionally NOT used.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', implode(',', [
        'https://thetaxi.lk',
        'https://www.thetaxi.lk',
        'https://dev.casons.lk',
        'http://localhost:4200',
        'http://localhost:4300',
    ]))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'Accept',
        'X-XSRF-TOKEN',
        'X-Company-Context',
    ],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => true,
];
