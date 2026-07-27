<?php

use App\Http\Controllers\Api\AgreementController;
use Illuminate\Support\Facades\Route;

it('exposes agreement templates and signing with the permissions used by the portal', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    $expected = [
        ['GET', 'api/agreement-templates', 'templates', ['permission:agreement-templates.view']],
        ['GET', 'api/agreement-templates/{template}', 'showTemplate', ['permission:agreement-templates.view']],
        ['POST', 'api/agreement-templates', 'storeTemplate', ['permission:agreement-templates.create']],
        ['PUT', 'api/agreement-templates/{template}', 'updateTemplate', ['permission:agreement-templates.edit']],
        ['DELETE', 'api/agreement-templates/{template}', 'destroyTemplate', ['permission:agreement-templates.delete']],
        ['POST', 'api/agreements/{agreement}/verify-identity', 'verifyIdentity', ['permission:agreements.view', 'permission:agreement-signing.edit']],
        ['POST', 'api/agreements/{agreement}/sign', 'sign', ['permission:agreements.view', 'permission:agreement-signing.edit']],
        ['POST', 'api/agreements/{agreement}/email-signed', 'emailSignedAgreement', ['permission:agreements.view', 'permission:agreement-signing.edit']],
    ];

    foreach ($expected as [$method, $uri, $controllerMethod, $permissions]) {
        $route = $routes->first(fn ($candidate) =>
            $candidate->uri() === $uri
            && in_array($method, $candidate->methods(), true)
        );

        expect($route)->not->toBeNull()
            ->and($route->getActionName())->toBe(AgreementController::class.'@'.$controllerMethod);

        foreach ($permissions as $permission) {
            expect($route->gatherMiddleware())->toContain($permission);
        }
    }
});

it('does not make agreement creation and mutation depend on agreements view', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    $contracts = [
        ['POST', 'api/agreements', 'permission:agreements.create'],
        ['PUT', 'api/agreements/{agreement}', 'permission:agreements.edit'],
        ['DELETE', 'api/agreements/{agreement}', 'permission:agreements.delete'],
    ];

    foreach ($contracts as [$method, $uri, $permission]) {
        $route = $routes->first(fn ($candidate) =>
            $candidate->uri() === $uri
            && in_array($method, $candidate->methods(), true)
        );

        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain($permission)
            ->and($route->gatherMiddleware())->not->toContain('permission:agreements.view');
    }
});
