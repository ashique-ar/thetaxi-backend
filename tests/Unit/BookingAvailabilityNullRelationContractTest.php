<?php

uses(Tests\TestCase::class);

it('keeps availability conflict joins aligned with booking model scopes', function () {
    $service = file_get_contents(app_path('Services/BookingFlowService.php'));

    expect(substr_count($service, "->whereNull('bookings.deleted_at')"))
        ->toBeGreaterThanOrEqual(2)
        ->and(substr_count($service, "->where('bookings.is_active', true)"))
        ->toBeGreaterThanOrEqual(2);
});

it('records source context when vehicle group availability fails', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Booking/Traits/BookingAvailabilityTrait.php'));

    expect($controller)
        ->toContain("Log::error('Error getting vehicle groups availability', [")
        ->toContain("'file' => \$e->getFile()")
        ->toContain("'line' => \$e->getLine()");
});

it('only accepts UUID booking identifiers on assignment detail routes', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)
        ->toContain("Route::get('{bookingId}/details'")
        ->toContain("->whereUuid('bookingId')");
});
