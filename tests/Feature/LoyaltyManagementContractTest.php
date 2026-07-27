<?php

use App\Http\Controllers\Api\LoyaltyController;
use Illuminate\Support\Facades\Route;

it('exposes the supported loyalty management contracts', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    $expected = [
        ['POST', 'api/customers/{customer}/loyalty/points', 'addPoints', 'permission:customers.loyalty'],
        ['POST', 'api/customers/{customer}/loyalty/adjustments', 'adjustPoints', 'permission:customers.loyalty'],
        ['GET', 'api/customers/loyalty/stats', 'getLoyaltyStats'],
        ['GET', 'api/customers/loyalty/leaderboard', 'getLoyaltyLeaderboard'],
        ['GET', 'api/customers/loyalty/tiers', 'getLoyaltyTiers'],
        ['GET', 'api/customers/loyalty/rewards', 'getLoyaltyRewards'],
        ['GET', 'api/customers/loyalty/activity', 'getLoyaltyActivity'],
        ['GET', 'api/customers/loyalty/export', 'exportLoyaltyData'],
    ];

    foreach ($expected as $contract) {
        [$method, $uri, $controllerMethod] = $contract;
        $permission = $contract[3] ?? 'permission:loyalty.view|customers.loyalty';
        $route = $routes->first(fn ($candidate) => $candidate->uri() === $uri && in_array($method, $candidate->methods(), true));

        expect($route)->not->toBeNull()
            ->and($route->getActionName())->toBe(LoyaltyController::class . '@' . $controllerMethod)
            ->and($route->gatherMiddleware())->toContain($permission);
    }

    expect($routes->contains(fn ($route) => in_array($route->uri(), ['api/point-types', 'api/users/{user}/badges/check'], true)))
        ->toBeFalse();
});
