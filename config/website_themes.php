<?php

return [
    'default' => 'default',

    'themes' => [
        'default' => [
            'label' => 'Default Theme',
            'released' => true,
            'preview' => '/assets/images/themes/default-preview.jpg',
            'stylesheet' => 'assets/css/theme-01.css',
            'checkout_stylesheet' => 'assets/css/checkout-theme-01.css',
            'required_partials' => [],
        ],

        'theme-02' => [
            'label' => 'Theme 02',
            'released' => true,
            'preview' => '/assets/images/themes/theme-02-preview.jpg',
            'stylesheet' => 'assets/css/theme-02-tw.css',
            'checkout_stylesheet' => 'assets/css/checkout-theme-02.css',
            'required_partials' => [],
        ],

        'theme-03' => [
            'label' => 'Theme 03 — Ceylon Modernist',
            'released' => (bool) env('WEBSITE_THEME_03_ENABLED', false),
            'preview' => null,
            'stylesheet' => 'assets/css/themes/theme-03/theme-03.css',
            'page_stylesheet' => 'assets/css/themes/theme-03/pages.css',
            'checkout_stylesheet' => 'assets/css/themes/theme-03/checkout.css',
            'script' => 'assets/js/themes/theme-03/shell.js',
            'required_partials' => ['header', 'hero', 'footer'],
        ],

        'theme-04' => [
            'label' => 'Theme 04 - Executive Redline',
            'released' => (bool) env('WEBSITE_THEME_04_ENABLED', false),
            'preview' => null,
            'stylesheet' => 'assets/css/themes/theme-04/theme-04.css',
            'page_stylesheet' => 'assets/css/themes/theme-04/pages.css',
            'checkout_stylesheet' => 'assets/css/themes/theme-04/checkout.css',
            'script' => 'assets/js/themes/theme-04/shell.js',
            'required_partials' => ['header', 'hero', 'footer'],
        ],
    ],
];
