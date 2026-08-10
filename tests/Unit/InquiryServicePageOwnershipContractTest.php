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

it('uses cms contents as the public services navigation owner', function () {
    $projectRoot = dirname(__DIR__, 2);
    $composer = file_get_contents((new ReflectionClass(ServicesViewComposer::class))->getFileName());

    expect($composer)
        ->toContain(CmsContent::class)
        ->toContain('CmsContent::published()')
        ->toContain("->byType('services')");

    foreach ([
        'resources/views/partials/header.blade.php',
        'resources/views/partials/themes/theme-02/header.blade.php',
        'resources/views/partials/themes/theme-03/header.blade.php',
        'resources/views/partials/themes/theme-04/header.blade.php',
    ] as $view) {
        expect(file_get_contents($projectRoot . '/' . $view))
            ->toContain("route('cms.show', ['contentType' => 'services'");
    }
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
