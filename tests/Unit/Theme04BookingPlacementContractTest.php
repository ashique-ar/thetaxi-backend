<?php

uses(Tests\TestCase::class);

it('keeps the Theme 4 hero form container aligned and the CMS form full width', function (): void {
    $partial = file_get_contents(resource_path('views/partials/themes/theme-04/booking-form.blade.php'));
    $theme = file_get_contents(public_path('assets/css/themes/theme-04/theme-04.css'));
    $pages = file_get_contents(public_path('assets/css/themes/theme-04/pages.css'));
    $cmsShow = file_get_contents(resource_path('views/cms/show.blade.php'));

    expect($partial)->toContain('container t4-booking-panel__inner')
        ->and($theme)->toContain('.t4-booking-panel__inner')
        ->and($pages)
        ->toContain('.article-sidebar .t4-booking-panel--embedded .t4-booking-panel__card { width: 100%; max-width: none; }')
        ->toContain('.cms-article-page { margin: 0 !important; padding: 32px 0 var(--t4-section); }')
        ->and($cmsShow)
        ->toContain("@if (is_theme('theme-04'))")
        ->toContain('.t4-booking-panel--embedded > .t4-booking-panel__inner,')
        ->toContain('padding-top: 32px;');
});
