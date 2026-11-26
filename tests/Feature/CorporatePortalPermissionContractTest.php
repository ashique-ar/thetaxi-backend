<?php

use Illuminate\Support\Facades\Route;

it('protects corporate profile, staff transport management and personal calendar endpoints separately', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    $contracts = [
        ['GET', 'api/corporate/workspace', 'permission:corporate.view'],
        ['GET', 'api/corporate/booking-configuration', 'ensure.corporate'],
        ['GET', 'api/corporate/profile', 'permission:manage_employees'],
        ['GET', 'api/corporate/service-types', 'permission:staff-transport.view|staff-transport.manage|staff-transport.override|staff-transport.generate|view_all_bookings'],
        ['GET', 'api/corporate/vehicle-groups', 'permission:staff-transport.view|staff-transport.manage|staff-transport.override|staff-transport.generate|view_all_bookings'],
        ['GET', 'api/corporate/departments', 'permission:manage_departments|manage_divisions|manage_employees|view_reports|view_all_bookings'],
        ['GET', 'api/corporate/staff-transport/employees', 'permission:staff-transport.view|staff-transport.manage|staff-transport.override|staff-transport.generate|view_all_bookings'],
        ['GET', 'api/corporate/bookings/stats', 'permission:view_reports|corporate.view|bookings.view|bookings.create|create_bookings|view_all_bookings'],
        ['GET', 'api/corporate/bookings/my', 'permission:bookings.view|bookings.create|create_bookings'],
        ['POST', 'api/corporate/bookings', 'permission:create_bookings|bookings.create'],
        ['GET', 'api/corporate/bookings/{id}', 'permission:bookings.view|bookings.create|create_bookings|view_all_bookings|approve_bookings'],
        ['POST', 'api/corporate/bookings/{id}/recurring/cancel', 'permission:bookings.create|create_bookings|view_all_bookings'],
        ['GET', 'api/corporate/staff-transport/programs', 'permission:staff-transport.view|staff-transport.manage|staff-transport.override|staff-transport.generate|view_all_bookings'],
        ['GET', 'api/corporate/staff-transport/my-calendar', 'permission:staff-transport.view|staff-transport.manage|staff-transport.override|staff-transport.generate|view_all_bookings|staff-transport.self.view'],
        ['POST', 'api/corporate/staff-transport/my-calendar/{participation}/status', 'permission:staff-transport.self.view|staff-transport.manage|staff-transport.override'],
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

it('protects monthly corporate billing routes with internal context and matching grants', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    $contracts = [
        ['GET', 'api/admin/corporates/{corporate}/billing/terms', 'permission:corporates.view|corporates.manage'],
        ['POST', 'api/admin/corporates/{corporate}/billing/terms', 'permission:corporates.manage'],
        ['PATCH', 'api/admin/corporates/{corporate}/billing/terms/{term}/end', 'permission:corporates.manage'],
        ['POST', 'api/admin/corporates/{corporate}/billing/preview', 'permission:bookings.view'],
        ['POST', 'api/admin/corporates/{corporate}/billing/generate', 'permission:bookings.update'],
        ['POST', 'api/admin/corporates/{corporate}/billing/settlements/{settlement}/issue', 'permission:bookings.update'],
    ];

    foreach ($contracts as [$method, $uri, $permission]) {
        $route = $routes->first(fn ($candidate) =>
            $candidate->uri() === $uri && in_array($method, $candidate->methods(), true)
        );

        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain($permission)
            ->and($route->gatherMiddleware())->toContain('ensure.internal');
    }
});
