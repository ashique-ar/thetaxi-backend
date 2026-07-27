<?php

use Illuminate\Support\Facades\Route;

it('lets each agent workflow enforce its own mounted permission', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    $contracts = [
        ['GET', 'api/agents', 'permission:agents.view'],
        ['POST', 'api/agents', 'permission:agents.create'],
        ['PUT', 'api/agents/{agent}', 'permission:agents.edit'],
        ['DELETE', 'api/agents/{agent}', 'permission:agents.delete'],
        ['GET', 'api/agents/api-management', 'permission:agent-apis.view'],
        ['POST', 'api/agents/api-management', 'permission:agent-apis.create'],
        ['PUT', 'api/agents/api-management/{agentApi}', 'permission:agent-apis.edit'],
        ['DELETE', 'api/agents/api-management/{agentApi}', 'permission:agent-apis.delete'],
    ];

    foreach ($contracts as [$method, $uri, $permission]) {
        $route = $routes->first(fn ($candidate) =>
            $candidate->uri() === $uri
            && in_array($method, $candidate->methods(), true)
        );

        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain($permission);

        if ($permission !== 'permission:agents.view') {
            expect($route->gatherMiddleware())->not->toContain('permission:agents.view');
        }
    }
});
