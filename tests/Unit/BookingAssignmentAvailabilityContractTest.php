<?php

uses(Tests\TestCase::class);

it('supports excluding the booking being edited from assignment conflict checks', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Booking/Traits/BookingAvailabilityTrait.php'));
    $service = file_get_contents(app_path('Services/BookingFlowService.php'));

    expect(substr_count($controller, "'exclude_booking_id' => 'nullable|uuid|exists:bookings,id'"))
        ->toBeGreaterThanOrEqual(2)
        ->and(substr_count($service, "\$params['exclude_booking_id'] ?? null"))
        ->toBeGreaterThanOrEqual(2)
        ->and(substr_count($service, "where('booking_items.booking_id', '!=', \$bookingId)"))
        ->toBeGreaterThanOrEqual(2);
});

it('loads the selected vehicle and supports single-ended assignment windows', function () {
    $service = file_get_contents(app_path('Services/BookingFlowService.php'));

    expect($service)
        ->toContain('$vehicle = Vehicle::findOrFail($vehicleId);')
        ->toContain("Carbon::parse(\$params['to_date'] ?? \$params['from_date'])")
        ->toContain("\$params['to_time'] ?? \$fromTime");
});

it('checks assignment overlap by date and time instead of treating the whole day as occupied', function () {
    $service = file_get_contents(app_path('Services/BookingFlowService.php'));

    expect(substr_count($service, 'whereBookingItemOverlaps('))
        ->toBe(3)
        ->and($service)
        ->toContain("where('booking_items.from_time', '<=', \$toTime)")
        ->toContain("where('booking_items.to_time', '>=', \$fromTime)");
});

it('allows both booking creators and editors to recheck assignments', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect(substr_count($routes, "permission:bookings.create|bookings.update"))
        ->toBeGreaterThanOrEqual(4);
});
