<?php

return [
    'default' => 'default',

    'themes' => [
        'default' => [
            'label' => 'Default Theme',
            'description' => 'Classic travel booking presentation with the established public-site layout.',
            'released' => true,
            'preview' => '/assets/images/themes/default-preview.jpg',
            'stylesheet' => 'assets/css/theme-01.css',
            'checkout_stylesheet' => 'assets/css/checkout-theme-01.css',
            'required_partials' => [],
        ],

        'theme-02' => [
            'label' => 'Theme 02',
            'description' => 'Contemporary travel presentation with the established Theme 02 hero and navigation.',
            'released' => true,
            'preview' => '/assets/images/themes/theme-02-preview.jpg',
            'stylesheet' => 'assets/css/theme-02-tw.css',
            'checkout_stylesheet' => 'assets/css/checkout-theme-02.css',
            'required_partials' => [],
        ],

        'theme-03' => [
            'label' => 'Theme 03 — Ceylon Modernist',
            'description' => 'Editorial, high-contrast Sri Lankan travel design with warm paper surfaces and bold typography.',
            'released' => (bool) env('WEBSITE_THEME_03_ENABLED', false),
            'preview' => '/assets/images/themes/theme-03-preview.svg',
            'stylesheet' => 'assets/css/themes/theme-03/theme-03.css',
            'page_stylesheet' => 'assets/css/themes/theme-03/pages.css',
            'checkout_stylesheet' => 'assets/css/themes/theme-03/checkout.css',
            'script' => 'assets/js/themes/theme-03/shell.js',
            'required_partials' => ['header', 'hero', 'footer'],
        ],

        'theme-04' => [
            'label' => 'Theme 04 - Executive Redline',
            'description' => 'Precision-led executive transport design derived from the approved red, black, and white reference.',
            'released' => (bool) env('WEBSITE_THEME_04_ENABLED', false),
            'preview' => '/assets/images/themes/theme-04-preview.svg',
            'stylesheet' => 'assets/css/themes/theme-04/theme-04.css',
            'page_stylesheet' => 'assets/css/themes/theme-04/pages.css',
            'checkout_stylesheet' => 'assets/css/themes/theme-04/checkout.css',
            'script' => 'assets/js/themes/theme-04/shell.js',
            'required_partials' => ['header', 'hero', 'footer'],
        ],
    ],
];
