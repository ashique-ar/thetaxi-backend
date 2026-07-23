<?php

use App\Http\Controllers\Api\GamificationController;
use Illuminate\Support\Facades\Route;

it('exposes the supported read-only leaderboard contract', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    $route = $routes->first(fn ($candidate) =>
        $candidate->uri() === 'api/leaderboard'
        && in_array('GET', $candidate->methods(), true)
    );

    expect($route)->not->toBeNull()
        ->and($route->getActionName())->toBe(GamificationController::class . '@getLeaderboard')
        ->and($route->gatherMiddleware())->toContain('permission:gamification.view')
        ->and($routes->contains(fn ($candidate) =>
            $candidate->uri() === 'api/leaderboard'
            && count(array_intersect($candidate->methods(), ['POST', 'PUT', 'PATCH', 'DELETE'])) > 0
        ))->toBeFalse();
});
