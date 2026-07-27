<?php

use App\Http\Controllers\Api\BookingObservabilityController;
use Illuminate\Support\Facades\Route;

it('keeps booking documents read-only until a real generation lifecycle exists', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    $documents = $routes->first(fn ($candidate) =>
        $candidate->uri() === 'api/bookings/{booking}/documents'
        && in_array('GET', $candidate->methods(), true)
    );

    expect($documents)->not->toBeNull()
        ->and($documents->getActionName())->toBe(BookingObservabilityController::class.'@documents')
        ->and($documents->gatherMiddleware())->toContain('permission:bookings.view');

    expect($routes->contains(fn ($candidate) =>
        $candidate->uri() === 'api/bookings/{booking}/generate-documents'
        && count(array_intersect($candidate->methods(), ['POST', 'PUT', 'PATCH'])) > 0
    ))->toBeFalse();
});
