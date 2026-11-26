<?php

use Illuminate\Support\Facades\Route;

it('requires payment initiation permission to create a staff payment link', function (): void {
    $route = collect(Route::getRoutes()->getRoutes())->first(fn ($candidate) =>
        $candidate->uri() === 'api/staff-payment-links' && in_array('POST', $candidate->methods(), true)
    );

    expect($route)->not->toBeNull()
        ->and($route->middleware())->toContain('ensure.internal')
        ->and($route->middleware())->toContain('permission:payments.initiate');
});
