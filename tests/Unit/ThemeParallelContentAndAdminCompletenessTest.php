<?php

use App\Http\Controllers\Api\Website\WebsiteSettingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

it('covers every Phase 6 content service and inquiry view in both scoped page bundles', function () {
    $root = dirname(__DIR__, 2);
    $views = [
        'about.blade.php', 'contact.blade.php', 'faq.blade.php',
        'point-to-point.blade.php', 'corporate-transfers.blade.php', 'rate-chart.blade.php',
        'cms/index.blade.php', 'cms/show.blade.php', 'cms/search.blade.php', 'cms/featured.blade.php',
        'inquiry/service-page.blade.php',
    ];

    foreach ($views as $view) {
        expect(file_exists($root . '/resources/views/' . $view))->toBeTrue();
    }

    foreach (['theme-03', 'theme-04'] as $theme) {
        $css = file_get_contents($root . "/public/assets/css/themes/{$theme}/pages.css");
        foreach (['about', 'contact', 'inquiry', 'faq', 'cms-index', 'cms-show', 'cms-search',
            'cms-featured', 'point-to-point', 'corporate-transfers', 'inquiry-services-show', 'rate-chart'] as $slug) {
            expect($css)->toContain(".theme-page-{$slug}");
        }
    }
});

it('keeps every inquiry builder section and form owner intact', function () {
    $root = dirname(__DIR__, 2);
    $page = file_get_contents($root . '/resources/views/inquiry/service-page.blade.php');

    foreach (['hero', 'form_block', 'content_block', 'features', 'services', 'benefits', 'faq', 'contact_info'] as $section) {
        expect($page)->toContain("@case('{$section}')")
            ->and(file_exists($root . "/resources/views/inquiry/sections/{$section}.blade.php"))->toBeTrue();
    }

    expect($page)->toContain("@include('partials.seo'")
        ->and(file_get_contents($root . '/resources/views/inquiry/partials/form.blade.php'))
        ->toContain('method="POST"')->toContain('@csrf');
});

it('publishes complete manifest metadata while returning only released selector options', function () {
    $manifest = config('website_themes.themes');
    foreach (['theme-03', 'theme-04'] as $theme) {
        expect($manifest[$theme]['label'])->not->toBeEmpty()
            ->and($manifest[$theme]['description'])->not->toBeEmpty()
            ->and($manifest[$theme]['preview'])->toEndWith("{$theme}-preview.svg")
            ->and($manifest[$theme]['released'])->toBeFalse();
    }

    $source = file_get_contents((new ReflectionClass(WebsiteSettingController::class))->getFileName());
    expect($source)->toContain("'theme_options' => \$this->releasedThemeOptions()")
        ->toContain('collect(get_allowed_themes())')
        ->toContain("The selected website theme is not available.");
});

it('invalidates the active theme cache and sanitizes stale stored identifiers', function () {
    Cache::put('active_theme_setting', 'theme-02', 3600);
    app(App\Services\WebsiteSettingsService::class)->clearCache('active_theme');
    expect(Cache::has('active_theme_setting'))->toBeFalse()
        ->and(normalize_theme_identifier('theme-03'))->toBe('default')
        ->and(normalize_theme_identifier('theme-04'))->toBe('default');
});

it('resolves every representative public route with each unreleased theme forced only inside the test', function () {
    $routes = ['home', 'about', 'contact', 'inquiry', 'faq', 'point-to-point', 'corporate-transfers',
        'rate-chart', 'cms.index', 'cms.search', 'cms.featured', 'inquiry-services.show', 'booking.status', 'checkout'];

    foreach (['theme-03', 'theme-04'] as $theme) {
        config()->set("website_themes.themes.{$theme}.released", true);
        Cache::put('active_theme_setting', $theme, 60);
        expect(get_active_theme())->toBe($theme);

        foreach ($routes as $name) {
            expect(app('router')->getRoutes()->getByName($name))->not->toBeNull();
        }
    }

    Cache::forget('active_theme_setting');
});

