<?php

test('only Theme 04 loads its readable UI baseline last', function () {
    $root = dirname(__DIR__, 2);
    $layout = file_get_contents($root . '/resources/views/layouts/app.blade.php');
    $styles = file_get_contents($root . '/public/assets/css/ui-ux.css');

    expect($layout)
        ->toContain("@if (is_theme('theme-04'))\n        <link rel=\"stylesheet\" href=\"{{ assetVersion('assets/css/ui-ux.css') }}\">\n    @endif")
        ->and(strpos($layout, "assetVersion('assets/css/ui-ux.css')"))
        ->toBeGreaterThan(strpos($layout, "theme_asset('page_stylesheet')"))
        ->and($styles)
        ->toContain('font-size: max(1rem, 16px)')
        ->toContain('min-height: 48px')
        ->toContain(':focus-visible')
        ->toContain('@media (prefers-reduced-motion: reduce)');
});
