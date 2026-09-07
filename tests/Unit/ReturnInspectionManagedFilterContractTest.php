<?php

it('uses bounded authorized booking references for return inspection filters', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Booking/Traits/BookingSubmissionTrait.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/booking/components/return-inspection-management/return-inspection-management.component.html'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/booking/components/return-inspection-management/return-inspection-management.component.ts'));

    expect($routes)->toContain("Route::get('return-inspection/options'")
        ->and($routes)->toContain("->middleware('permission:bookings.view')")
        ->and($controller)->toContain("'record_type' => 'required|string|in:customer,vehicle,driver'")
        ->and($controller)->toContain("'per_page' => 'nullable|integer|min:1|max:50'")
        ->and($controller)->toContain('$this->bookingFlowService->getFilteredBookings($filters)')
        ->and($controller)->toContain("->unique('value')")
        ->and($template)->toContain('endpoint="/booking-flow/return-inspection/options"')
        ->and($template)->toContain('recordType="customer"')
        ->and($template)->toContain('recordType="vehicle"')
        ->and($template)->toContain('recordType="driver"')
        ->and($template)->not->toContain('<mat-label>Customer ID</mat-label>')
        ->and($template)->not->toContain('<mat-label>Vehicle ID</mat-label>')
        ->and($template)->not->toContain('<mat-label>Driver ID</mat-label>')
        ->and($component)->not->toContain('Customer: ${value.customer_id}')
        ->and($component)->not->toContain('Vehicle: ${value.vehicle_id}')
        ->and($component)->not->toContain('Driver: ${value.driver_id}');
});
