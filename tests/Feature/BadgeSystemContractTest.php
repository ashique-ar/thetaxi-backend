<?php

use App\Http\Controllers\Api\GamificationController;
use App\Http\Controllers\Api\LoyaltyController;
use Illuminate\Support\Facades\Route;

it('exposes the supported read-only badge system contracts with their permissions', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    $expected = [
        ['api/badges', GamificationController::class . '@getAllBadges', 'permission:gamification.view'],
        ['api/badges/stats', GamificationController::class . '@getBadgeStats', 'permission:gamification.stats'],
        ['api/customers/loyalty/activity', LoyaltyController::class . '@getLoyaltyActivity', 'permission:loyalty.view|customers.loyalty'],
    ];

    foreach ($expected as [$uri, $action, $permission]) {
        $route = $routes->first(fn ($candidate) => $candidate->uri() === $uri && in_array('GET', $candidate->methods(), true));

        expect($route)->not->toBeNull()
            ->and($route->getActionName())->toBe($action)
            ->and($route->gatherMiddleware())->toContain($permission);
    }

    $unsupportedUris = [
        'api/badges',
        'api/badges/{id}',
        'api/badges/{id}/holders',
        'api/badges/{id}/check',
    ];

    expect($routes->contains(fn ($route) =>
        in_array($route->uri(), $unsupportedUris, true)
        && count(array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE'])) > 0
    ))->toBeFalse();
});
