<?php

it('keeps both preview themes fail closed until external release approval', function () {
    $root = dirname(__DIR__, 2);
    $env = file_get_contents($root . '/.env.example');
    $phpunit = file_get_contents($root . '/phpunit.xml');

    foreach (['03', '04'] as $theme) {
        expect($env)->toContain("WEBSITE_THEME_{$theme}_ENABLED=false")
            ->and($phpunit)->toContain("<env name=\"WEBSITE_THEME_{$theme}_ENABLED\" value=\"false\"/>");
    }

    expect(get_allowed_themes())->toBe(['default', 'theme-02']);
});

it('retains accessibility stress and reduced-motion protections in both theme systems', function () {
    $root = dirname(__DIR__, 2);

    foreach (['theme-03', 'theme-04'] as $theme) {
        $foundation = file_get_contents($root . "/public/assets/css/themes/{$theme}/{$theme}.css");
        $pages = file_get_contents($root . "/public/assets/css/themes/{$theme}/pages.css");
        $checkout = file_get_contents($root . "/public/assets/css/themes/{$theme}/checkout.css");
        $combined = $foundation . $pages . $checkout;

        expect($combined)->toContain(':focus-visible')
            ->toContain('@media (prefers-reduced-motion: reduce)')
            ->toContain('overflow-wrap: anywhere')
            ->toContain('min-height: 44px')
            ->not->toContain("body.theme-" . ($theme === 'theme-03' ? 'theme-04' : 'theme-03'));
    }
});

it('retains one-setting rollback through the normalized active theme resolver', function () {
    foreach (['default', 'theme-02', 'theme-03', 'theme-04'] as $theme) {
        config()->set("website_themes.themes.{$theme}.released", true);
    }

    expect(normalize_theme_identifier('theme-03'))->toBe('theme-03')
        ->and(normalize_theme_identifier('theme-04'))->toBe('theme-04')
        ->and(normalize_theme_identifier('theme-02'))->toBe('theme-02');
});

