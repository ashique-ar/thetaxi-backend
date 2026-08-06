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

it('keeps specific public owners ahead of the CMS catch alls', function () {
    $routesSource = file_get_contents(publicThemeProjectPath('routes/web.php'));
    $dynamicPosition = strpos($routesSource, "Route::get('/{contentType}/{content}'");
    $servicePosition = strpos($routesSource, "Route::get('/services/{slug}'");

    expect($dynamicPosition)->toBeInt()
        ->and($servicePosition)->toBeInt()->toBeLessThan($dynamicPosition);

    $routes = new RouteCollection();
    $routes->add(new Route(['GET'], '/services/{slug}', fn () => null));
    $routes->add((new Route(['GET'], '/{contentType}/{content}', fn () => null))
        ->where('contentType', '[a-zA-Z0-9-_]+')
        ->where('content', '[a-zA-Z0-9-_]+'));

    expect($routes->match(Request::create('/services/airport-transfer', 'GET'))->uri())
        ->toBe('services/{slug}');
});

it('does not expose diagnostic cache mutation or orphan system routes', function () {
    $routes = file_get_contents(publicThemeProjectPath('routes/web.php'));

    expect($routes)
        ->not->toContain('/clear-all-caches')
        ->not->toContain('/check-services-debug')
        ->not->toContain("Route::get('/test'")
        ->not->toContain('/test-render')
        ->not->toContain("Route::get('/system/{any?}'")
        ->not->toContain('Artisan::call(')
        ->not->toContain('DebugController');
});

it('keeps booking confirmation customers on the public verified status owner', function () {
    $success = file_get_contents(publicThemeProjectPath('resources/views/booking/success.blade.php'));
    $status = file_get_contents(publicThemeProjectPath('resources/views/booking/status.blade.php'));
    $routes = file_get_contents(publicThemeProjectPath('routes/web.php'));

    expect($success)
        ->toContain("route('booking.status', ['booking_reference' => \$bookingReference])")
        ->toContain('View Booking Status')
        ->not->toContain("route('bookings.view'");

    expect($status)
        ->toContain("old('booking_reference', request()->query('booking_reference', ''))");

    expect($routes)
        ->toContain("Route::get('/booking/status', [CustomerBookingStatusController::class, 'show'])->name('booking.status')")
        ->toContain("Route::post('/booking/status', [CustomerBookingStatusController::class, 'lookup'])");
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
    ]);

    $webRoutes = file_get_contents(publicThemeProjectPath('routes/web.php'));
    expect($webRoutes)->not->toContain('mockGateway');
});
