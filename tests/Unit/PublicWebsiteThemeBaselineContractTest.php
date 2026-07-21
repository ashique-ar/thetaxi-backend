<?php

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;

function publicThemeProjectPath(string $path = ''): string
{
    $root = dirname(__DIR__, 2);

    return $path === '' ? $root : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
}

it('keeps the legacy vehicles URL on the canonical CMS fleet owner', function () {
    $routes = file_get_contents(publicThemeProjectPath('routes/web.php'));

    expect($routes)
        ->toContain("Route::get('/vehicles', fn () => redirect()->route('cms.index', ['contentType' => 'ride_now']))")
        ->not->toContain("return view('vehicles')");
});

it('preserves every configured booking tab and dynamic field contract for theme work', function () {
    $bookingForm = file_get_contents(publicThemeProjectPath('resources/views/components/booking-form.blade.php'));
    $dynamicForm = file_get_contents(publicThemeProjectPath('resources/views/components/dynamic-booking-form.blade.php'));
    $dynamicField = file_get_contents(publicThemeProjectPath('resources/views/components/dynamic-form-field.blade.php'));

    foreach ([
        'airport_transfers',
        'ride_now',
        'day_rental',
        'corporate',
        'wedding_hire',
        'self_drive',
        'with_driver',
    ] as $serviceCode) {
        expect($bookingForm)->toContain("'{$serviceCode}'");
    }

    expect($bookingForm)
        ->toContain("BookingFormTab::getOrderedTabs()")
        ->toContain('@foreach ($bookingTabs as $tab)')
        ->toContain('data-service="{{ $tab->code }}"')
        ->toContain('data-form-service="{{ $getFormServiceCodeForTab($tab) }}"')
        ->toContain("@include('components.dynamic-booking-form'");

    expect($dynamicForm)
        ->toContain("route('booking.enquiry')")
        ->toContain("route('booking.search')")
        ->toContain('data-service="{{ $serviceCode }}"')
        ->toContain('<input type="hidden" name="service_type" value="{{ $serviceCode }}">')
        ->toContain('ride_now-return-toggle')
        ->toContain('ride_now-return-date')
        ->toContain('ride_now-return-time');

    foreach (['location', 'date', 'time', 'select', 'radio', 'checkbox', 'textarea', 'number', 'hidden', 'package_select'] as $type) {
        expect($dynamicField)->toContain("@case('{$type}')");
    }

    foreach (['predefined_or_custom', 'airport', 'conditional'] as $locationMode) {
        expect($dynamicField)->toContain("@case('{$locationMode}')");
    }
});

it('documents the effective owner of routes declared after the CMS catch alls', function () {
    $routesSource = file_get_contents(publicThemeProjectPath('routes/web.php'));
    $dynamicPosition = strpos($routesSource, "Route::get('/{contentType}/{content}'");
    $servicePosition = strpos($routesSource, "Route::get('/services/{slug}'");
    $systemPosition = strpos($routesSource, "Route::get('/system/{any?}'");

    expect($dynamicPosition)->toBeInt()
        ->and($servicePosition)->toBeInt()->toBeGreaterThan($dynamicPosition)
        ->and($systemPosition)->toBeInt()->toBeGreaterThan($dynamicPosition);

    $routes = new RouteCollection();
    $routes->add((new Route(['GET'], '/{contentType}/{content}', fn () => null))
        ->where('contentType', '[a-zA-Z0-9-_]+')
        ->where('content', '[a-zA-Z0-9-_]+'));
    $routes->add(new Route(['GET'], '/services/{slug}', fn () => null));
    $routes->add((new Route(['GET'], '/system/{any?}', fn () => null))->where('any', '.*'));

    expect($routes->match(Request::create('/services/airport-transfer', 'GET'))->uri())
        ->toBe('{contentType}/{content}')
        ->and($routes->match(Request::create('/system/settings', 'GET'))->uri())
        ->toBe('{contentType}/{content}')
        ->and($routes->match(Request::create('/system/settings/appearance', 'GET'))->uri())
        ->toBe('system/{any?}');
});

it('keeps known missing literal views limited to non-public or unregistered legacy paths', function () {
    $root = publicThemeProjectPath();
    $sources = [publicThemeProjectPath('routes/web.php')];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(publicThemeProjectPath('app/Http/Controllers'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $sources[] = $file->getPathname();
        }
    }

    $missing = [];
    foreach ($sources as $source) {
        $contents = file_get_contents($source);
        preg_match_all('/view\(\s*[\'\"]([^\'\"]+)[\'\"]/', $contents, $matches);

        foreach ($matches[1] as $view) {
            $viewPath = publicThemeProjectPath('resources/views/' . str_replace('.', '/', $view) . '.blade.php');
            if (!is_file($viewPath)) {
                $missing[$view] = true;
            }
        }
    }

    $missing = array_keys($missing);
    sort($missing);

    expect($missing)->toBe([
        'admin.analytics.short-urls',
        'checkout.mock-gateway',
        'layout.system-app',
    ]);

    $webRoutes = file_get_contents(publicThemeProjectPath('routes/web.php'));
    expect($webRoutes)->not->toContain('mockGateway');
});
