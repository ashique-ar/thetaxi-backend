<?php

use App\Http\ViewComposers\ServicesViewComposer;
use App\Http\Controllers\Api\InquiryServicePageController;
use App\Models\Website\CmsContent;
it('registers the cms-first service route before the generic cms detail route', function () {
    $projectRoot = dirname(__DIR__, 2);
    $routes = file_get_contents($projectRoot . '/routes/web.php');

    $inquiryRoute = strpos($routes, "Route::get('/services/{slug}'");
    $cmsRoute = strpos($routes, "Route::get('/{contentType}/{content}'");

    expect($inquiryRoute)->not->toBeFalse()
        ->and($cmsRoute)->not->toBeFalse()
        ->and($inquiryRoute)->toBeLessThan($cmsRoute)
        ->and($routes)->toContain("->name('inquiry-services.show')");
});

it('invalidates navigation sitemap and rendered page caches after management changes', function () {
    $controller = file_get_contents((new ReflectionClass(InquiryServicePageController::class))->getFileName());

    expect($controller)
        ->toContain("Cache::forget('header_services')")
        ->toContain("Cache::forget('sitemap')")
        ->toContain("Cache::forget('inquiry_service_page:' . \$slug)")
        ->toContain('$this->clearPublicPageCaches($page->slug)')
        ->toContain('$this->clearPublicPageCaches($oldSlug, $inquiry_service_page->slug)')
        ->toContain('$this->clearPublicPageCaches($slug)');
});

it('uses the admin-managed navigation as every theme header owner', function () {
    $projectRoot = dirname(__DIR__, 2);
    $composer = file_get_contents($projectRoot . '/app/Http/ViewComposers/NavigationViewComposer.php');

    expect($composer)
        ->toContain('NavigationMenu::headerNavigation()')
        ->toContain("->with(['children'");

    foreach ([
        'resources/views/partials/header.blade.php',
        'resources/views/partials/themes/theme-02/header.blade.php',
        'resources/views/partials/themes/theme-03/header.blade.php',
        'resources/views/partials/themes/theme-04/header.blade.php',
    ] as $view) {
        expect(file_get_contents($projectRoot . '/' . $view))
            ->toContain("@include('partials.header-navigation'");
    }

    $sharedHeader = file_get_contents($projectRoot . '/resources/views/partials/header-navigation.blade.php');
    expect($sharedHeader)
        ->toContain('$hasCmsServices')
        ->toContain("route('cms.show', ['contentType' => 'services'");
});

it('seeds each company theme with its own actual header links', function () {
    $projectRoot = dirname(__DIR__, 2);
    $themeOne = file_get_contents($projectRoot . '/database/seeders/ThemeOneCompanySeeder.php');
    $themeTwo = file_get_contents($projectRoot . '/database/seeders/ThemeTwoCompanySeeder.php');

    foreach (['Services', 'Corporate Transport', 'Rate Chart', 'About', 'Inquiry'] as $label) {
        expect($themeOne)->toContain("['{$label}',");
    }

    expect($themeTwo)
        ->toContain("['Services',")
        ->toContain("['About',")
        ->toContain("['Inquiry',")
        ->not->toContain("['Corporate Transport',")
        ->not->toContain("['Rate Chart',");
});

it('resolves published cms services before legacy inquiry service pages', function () {
    $projectRoot = dirname(__DIR__, 2);
    $controller = file_get_contents($projectRoot . '/app/Http/Controllers/Website/InquiryServicePageController.php');

    expect($controller)
        ->toContain('CmsContent::published()')
        ->toContain("->byType('services')")
        ->toContain("app(CmsController::class)->show('services', \$slug)")
        ->toContain('InquiryServicePage::withInactive()');
});

it('reserves the theme four hero overlap so the next section is not clipped', function () {
    $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/themes/theme-04/theme-04.css');

    expect($css)
        ->toContain('min-height: calc(var(--t4-hero-height) - 26px);')
        ->toContain("@media (max-width: 991px)");
});
