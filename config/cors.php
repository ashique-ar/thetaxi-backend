<?php

$isDevEnvironment = in_array(env('APP_ENV', 'production'), ['local', 'development', 'testing'], true);

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

    'allowed_origins_patterns' => $isDevEnvironment ? [
        '#^https?://localhost(:[0-9]+)?$#',
        '#^https?://127\.0\.0\.1(:[0-9]+)?$#',
        '#^https?://.*\.test(:[0-9]+)?$#',
    ] : [],

    'allowed_headers' => $isDevEnvironment ? ['*'] : [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'Accept',
        'X-XSRF-TOKEN',
        'X-Company-Context',
        'user-location',
        'X-Req-Domain',
        'X-Active-Context-Type',
        'X-Active-Context-Id',
        'X-Active-Portal-Profile',
        'X-Currency-Code',
        'X-Display-Currency',
        'X-Selected-Currency',
    ],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => true,
];
