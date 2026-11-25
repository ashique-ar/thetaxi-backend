<?php

use Illuminate\Support\Facades\Route;

it('allows driver staff and website administrators to load the driver list lookups', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    $contracts = [
        ['GET', 'api/website-settings/driver-mobile/settings', 'permission:drivers.view|website-settings.view'],
        ['GET', 'api/driving-license-types', 'permission:drivers.view|drivers.create|drivers.edit|driving-license-types.view'],
    ];

    foreach ($contracts as [$method, $uri, $permission]) {
        $route = $routes->first(fn ($candidate) =>
            $candidate->uri() === $uri && in_array($method, $candidate->methods(), true)
        );

        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain($permission);
    }
});
