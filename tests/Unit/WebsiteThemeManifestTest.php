<?php

uses(Tests\TestCase::class);

it('keeps Theme 03 and Theme 04 unreleased by default', function () {
    expect(config('website_themes.themes.theme-03.released'))->toBeFalse()
        ->and(config('website_themes.themes.theme-04.released'))->toBeFalse()
        ->and(get_allowed_themes())->toBe(['default', 'theme-02'])
        ->and(normalize_theme_identifier('theme-03'))->toBe('default')
        ->and(normalize_theme_identifier('theme-04'))->toBe('default')
        ->and(normalize_theme_identifier('not-a-theme'))->toBe('default');
});

it('allows Theme 04 only when its independent release gate is enabled', function () {
    config()->set('website_themes.themes.theme-04.released', true);

    expect(get_allowed_themes())->toBe(['default', 'theme-02', 'theme-04'])
        ->and(normalize_theme_identifier('theme-04'))->toBe('theme-04')
        ->and(normalize_theme_identifier('theme-03'))->toBe('default');
});

it('allows Theme 03 only when its release gate is enabled', function () {
    config()->set('website_themes.themes.theme-03.released', true);

    expect(get_allowed_themes())->toBe(['default', 'theme-02', 'theme-03'])
        ->and(normalize_theme_identifier('theme-03'))->toBe('theme-03');
});

it('fails clearly when a mandatory Theme 03 partial is missing', function () {
    config()->set('website_themes.themes.theme-03.required_partials', [
        'header',
        'hero',
        'footer',
        'missing-required-example',
    ]);

    expect(fn () => theme_partial_for('theme-03', 'missing-required-example'))
        ->toThrow(LogicException::class, 'Required theme-03 partial');
});

it('fails clearly when a mandatory Theme 04 partial is missing', function () {
    config()->set('website_themes.themes.theme-04.required_partials', [
        'header',
        'hero',
        'footer',
        'missing-required-example',
    ]);

    expect(fn () => theme_partial_for('theme-04', 'missing-required-example'))
        ->toThrow(LogicException::class, 'Required theme-04 partial');
});

it('preserves optional fallback behavior and existing theme partials', function () {
    expect(theme_partial_for('default', 'header'))->toBe('partials.header')
        ->and(theme_partial_for('theme-02', 'header'))->toBe('partials.themes.theme-02.header')
        ->and(theme_partial_for('theme-03', 'header'))->toBe('partials.themes.theme-03.header')
        ->and(theme_partial_for('theme-03', 'hero'))->toBe('partials.themes.theme-03.hero')
        ->and(theme_partial_for('theme-03', 'footer'))->toBe('partials.themes.theme-03.footer')
        ->and(theme_partial_for('theme-04', 'header'))->toBe('partials.themes.theme-04.header')
        ->and(theme_partial_for('theme-04', 'hero'))->toBe('partials.themes.theme-04.hero')
        ->and(theme_partial_for('theme-04', 'footer'))->toBe('partials.themes.theme-04.footer')
        ->and(theme_partial_for('theme-02', 'optional-example'))->toBe('partials.optional-example')
        ->and(theme_partial_for('theme-03', 'optional-example'))->toBe('partials.optional-example')
        ->and(theme_partial_for('theme-04', 'optional-example'))->toBe('partials.optional-example');
});

it('registers isolated Theme 03 asset entry points without a runtime framework CDN', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
    $checkout = file_get_contents(resource_path('views/checkout.blade.php'));
    $themeStyles = file_get_contents(public_path('assets/css/themes/theme-03/theme-03.css'));

    expect(config('website_themes.themes.theme-03.stylesheet'))
        ->toBe('assets/css/themes/theme-03/theme-03.css')
        ->and(config('website_themes.themes.theme-03.checkout_stylesheet'))
        ->toBe('assets/css/themes/theme-03/checkout.css')
        ->and(config('website_themes.themes.theme-03.script'))
        ->toBe('assets/js/themes/theme-03/shell.js')
        ->and($layout)->toContain("@elseif (in_array(get_active_theme(), ['theme-03', 'theme-04'], true))")
        ->and($layout)->toContain("assetVersion(theme_asset('stylesheet'))")
        ->and($layout)->toContain("@if (theme_asset('script'))")
        ->and($layout)->toContain("assetVersion(theme_asset('script'))")
        ->and($checkout)->toContain("theme_asset('checkout_stylesheet')")
        ->and($themeStyles)->toContain('body.theme-theme-03')
        ->and($themeStyles)->not->toContain('cdn.tailwindcss.com');
});

it('registers isolated Theme 04 asset entry points without weakening Theme 03', function () {
    $theme04Styles = file_get_contents(public_path('assets/css/themes/theme-04/theme-04.css'));

    expect(config('website_themes.themes.theme-04.stylesheet'))->toBe('assets/css/themes/theme-04/theme-04.css')
        ->and(config('website_themes.themes.theme-04.page_stylesheet'))->toBe('assets/css/themes/theme-04/pages.css')
        ->and(config('website_themes.themes.theme-04.checkout_stylesheet'))->toBe('assets/css/themes/theme-04/checkout.css')
        ->and(config('website_themes.themes.theme-04.script'))->toBe('assets/js/themes/theme-04/shell.js')
        ->and(config('website_themes.themes.theme-03.stylesheet'))->toBe('assets/css/themes/theme-03/theme-03.css')
        ->and($theme04Styles)->toContain('body.theme-theme-04')
        ->not->toContain('body.theme-theme-03')
        ->not->toContain('cdn.tailwindcss.com');
});

it('compiles the Theme 03 homepage presenter views in the Laravel view container', function () {
    $compiler = app('blade.compiler');
    $views = [
        resource_path('views/home.blade.php'),
        resource_path('views/partials/themes/theme-03/hero.blade.php'),
        resource_path('views/partials/themes/theme-03/booking-form.blade.php'),
        resource_path('views/partials/themes/theme-03/partner-register.blade.php'),
        resource_path('views/partials/themes/theme-03/featured-vehicles.blade.php'),
        resource_path('views/partials/themes/theme-03/services.blade.php'),
        resource_path('views/partials/themes/theme-03/destinations.blade.php'),
        resource_path('views/partials/themes/theme-03/packages.blade.php'),
        resource_path('views/partials/themes/theme-03/offer-slider.blade.php'),
        resource_path('views/partials/themes/theme-03/why-choose-us.blade.php'),
        resource_path('views/partials/themes/theme-03/testimonials.blade.php'),
        resource_path('views/partials/themes/theme-03/blog-editorial.blade.php'),
        resource_path('views/partials/themes/theme-03/faq.blade.php'),
        resource_path('views/components/cms-card.blade.php'),
    ];

    foreach ($views as $view) {
        $compiled = $compiler->compileString(file_get_contents($view));
        expect($compiled)->toBeString()->not->toBeEmpty();
    }
});

it('compiles the Theme 04 shell and aligned homepage presenters in the Laravel view container', function () {
    $compiler = app('blade.compiler');
    $views = [
        resource_path('views/partials/themes/theme-04/header.blade.php'),
        resource_path('views/partials/themes/theme-04/hero.blade.php'),
        resource_path('views/partials/themes/theme-04/footer.blade.php'),
        resource_path('views/partials/themes/theme-04/booking-form.blade.php'),
        resource_path('views/partials/themes/theme-04/partner-register.blade.php'),
        resource_path('views/partials/themes/theme-04/featured-vehicles.blade.php'),
        resource_path('views/partials/themes/theme-04/services.blade.php'),
        resource_path('views/partials/themes/theme-04/destinations.blade.php'),
        resource_path('views/partials/themes/theme-04/packages.blade.php'),
        resource_path('views/partials/themes/theme-04/offer-slider.blade.php'),
        resource_path('views/partials/themes/theme-04/why-choose-us.blade.php'),
        resource_path('views/partials/themes/theme-04/testimonials.blade.php'),
        resource_path('views/partials/themes/theme-04/blog-editorial.blade.php'),
        resource_path('views/partials/themes/theme-04/faq.blade.php'),
    ];

    foreach ($views as $view) {
        $compiled = $compiler->compileString(file_get_contents($view));
        expect($compiled)->toBeString()->not->toBeEmpty();
    }
});
