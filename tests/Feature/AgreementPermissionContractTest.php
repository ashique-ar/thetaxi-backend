<?php

use App\Http\Controllers\Api\AgreementController;
use App\Models\Agreement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('exposes agreement templates with the permissions used by the portal', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    $expected = [
        ['GET', 'api/agreement-templates', 'templates', ['permission:agreement-templates.view']],
        ['GET', 'api/agreement-templates/{template}', 'showTemplate', ['permission:agreement-templates.view']],
        ['POST', 'api/agreement-templates', 'storeTemplate', ['permission:agreement-templates.create']],
        ['PUT', 'api/agreement-templates/{template}', 'updateTemplate', ['permission:agreement-templates.edit']],
        ['DELETE', 'api/agreement-templates/{template}', 'destroyTemplate', ['permission:agreement-templates.delete']],
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

it('exposes persisted agreement review and reporting without simulated signing routes', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    $expected = [
        ['GET', 'api/agreements/{agreement}', 'show'],
        ['GET', 'api/agreements/{agreement}/pdf', 'pdf'],
        ['GET', 'api/agreements/reports', 'reports'],
        ['GET', 'api/agreements/reports/export', 'exportReport'],
    ];

    foreach ($expected as [$method, $uri, $controllerMethod]) {
        $route = $routes->first(fn ($candidate) =>
            $candidate->uri() === $uri
            && in_array($method, $candidate->methods(), true)
        );

        expect($route)->not->toBeNull()
            ->and($route->getActionName())->toBe(AgreementController::class.'@'.$controllerMethod)
            ->and($route->gatherMiddleware())->toContain('permission:agreements.view');
    }

    foreach (['verify-identity', 'sign', 'email-signed'] as $action) {
        expect($routes->contains(fn ($route) =>
            $route->uri() === "api/agreements/{agreement}/{$action}"
        ))->toBeFalse();
    }
});

it('reports persisted agreement facts and applies the expiry filter', function (): void {
    Agreement::create([
        'title' => 'Expiring service agreement',
        'type' => 'service',
        'status' => 'active',
        'end_date' => now()->addDays(30),
    ]);
    Agreement::create([
        'title' => 'Open rental agreement',
        'type' => 'rental',
        'status' => 'draft',
    ]);

    $controller = app(AgreementController::class);
    $summary = $controller->reports(Request::create('/api/agreements/reports', 'GET', [
        'reportType' => 'summary',
    ]));
    $expiry = $controller->reports(Request::create('/api/agreements/reports', 'GET', [
        'reportType' => 'expiry',
    ]));

    expect($summary->getData(true)['items'])->toHaveCount(2)
        ->and($summary->getData(true)['stats']['total'])->toBe(2)
        ->and($expiry->getData(true)['items'])->toHaveCount(1)
        ->and($expiry->getData(true)['items'][0]['title'])->toBe('Expiring service agreement');
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
