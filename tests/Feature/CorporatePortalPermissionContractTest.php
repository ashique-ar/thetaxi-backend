<?php

use Illuminate\Support\Facades\Route;

it('protects corporate profile, staff transport management and personal calendar endpoints separately', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    $contracts = [
        ['GET', 'api/corporate/profile', 'permission:manage_employees'],
        ['GET', 'api/corporate/service-types', 'permission:staff-transport.view|staff-transport.manage|staff-transport.override|staff-transport.generate|view_all_bookings'],
        ['GET', 'api/corporate/vehicle-groups', 'permission:staff-transport.view|staff-transport.manage|staff-transport.override|staff-transport.generate|view_all_bookings'],
        ['GET', 'api/corporate/bookings/stats', 'permission:view_reports|corporate.view'],
        ['GET', 'api/corporate/staff-transport/programs', 'permission:staff-transport.view|staff-transport.manage|staff-transport.override|staff-transport.generate|view_all_bookings'],
        ['GET', 'api/corporate/staff-transport/my-calendar', 'permission:staff-transport.view|staff-transport.manage|staff-transport.override|staff-transport.generate|view_all_bookings|staff-transport.self.view'],
        ['POST', 'api/corporate/staff-transport/my-calendar/{participation}/status', 'permission:staff-transport.view|staff-transport.manage|staff-transport.override|staff-transport.generate|view_all_bookings|staff-transport.self.view'],
    ];

    foreach ($contracts as [$method, $uri, $permission]) {
        $route = $routes->first(fn ($candidate) =>
            $candidate->uri() === $uri && in_array($method, $candidate->methods(), true)
        );

        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain($permission)
            ->and($route->gatherMiddleware())->toContain('ensure.corporate');
    }
});
