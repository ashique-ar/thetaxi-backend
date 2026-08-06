<?php

use App\Http\Controllers\Api\Driver\DriverLogController;
use Illuminate\Support\Facades\Route;

it('applies driver and logsheet permissions to every active logsheet action', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    $expected = [
        ['GET', 'api/logsheets', 'index', 'permission:driver-logs.view'],
        ['GET', 'api/logsheets/stats', 'stats', 'permission:driver-logs.view'],
        ['GET', 'api/logsheets/dashboard', 'stats', 'permission:driver-logs.view'],
        ['GET', 'api/logsheets/{driverLog}', 'show', 'permission:driver-logs.view'],
        ['POST', 'api/logsheets', 'store', 'permission:driver-logs.create'],
        ['POST', 'api/logsheets/bulk-review', 'bulkReview', 'permission:driver-logs.edit'],
        ['PUT', 'api/logsheets/{driverLog}', 'update', 'permission:driver-logs.edit'],
        ['POST', 'api/logsheets/{driverLog}/assign', 'assign', 'permission:driver-logs.edit'],
        ['POST', 'api/logsheets/{driverLog}/submit', 'submit', 'permission:driver-logs.edit'],
        ['POST', 'api/logsheets/{driverLog}/verify', 'verify', 'permission:driver-logs.edit'],
        ['DELETE', 'api/logsheets/{driverLog}', 'destroy', 'permission:driver-logs.delete'],
    ];

    foreach ($expected as [$method, $uri, $action, $logPermission]) {
        $route = $routes->first(fn ($candidate) =>
            $candidate->uri() === $uri && in_array($method, $candidate->methods(), true)
        );

        expect($route)->not->toBeNull()
            ->and($route->getActionName())->toBe(DriverLogController::class . '@' . $action)
            ->and($route->gatherMiddleware())->toContain('permission:drivers.view')
            ->and($route->gatherMiddleware())->toContain($logPermission);
    }

    expect($routes->contains(fn ($candidate) => $candidate->uri() === 'api/logsheets/{driverLog}/cancel'))->toBeFalse();
});
