<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

return [
    /*
     * The path where the API documentation is served.
     */
    'api_path' => 'api',

    /*
     * The path prefix to strip when generating documentation.
     */
    'api_domain' => null,

    /*
     * Define which routes are documented.
     */
    'info' => [
        'version' => env('APP_VERSION', '1.0.0'),
        'description' => 'TheTaxi SaaS — white-label taxi and rent-a-car platform API.',
    ],

    /*
     * Middleware applied to the docs UI routes.
     * Use RestrictedDocsAccess to limit to specific environments.
     */
    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    /*
     * Add custom servers to the docs.
     */
    'servers' => null,

    'extensions' => [],
];
