<?php

use App\Http\Controllers\Api\DocumentController;
use App\Models\Vehicle\VehicleOwner;
use Illuminate\Support\Facades\Route;

it('uses the shared document lifecycle for vehicle owners', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    $contracts = [
        ['GET', 'api/documents', 'permission:documents.view|system.view|agreements.view|customers.view|drivers.view|staff.view|vehicles.view|vehicle-owners.view|vehicle-leases.view'],
        ['POST', 'api/documents', 'permission:documents.create|uploads.manage|customers.edit|drivers.edit|staff.edit|vehicles.edit|vehicle-owners.edit|vehicle-leases.edit'],
    ];

    foreach ($contracts as [$method, $uri, $permission]) {
        $route = $routes->first(fn ($candidate) =>
            $candidate->uri() === $uri
            && in_array($method, $candidate->methods(), true)
        );

        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain($permission);
    }

    $ownerTypes = (new \ReflectionClass(DocumentController::class))->getConstant('OWNER_TYPES');

    expect($ownerTypes)->toHaveKey('vehicle_owner', VehicleOwner::class);
});
