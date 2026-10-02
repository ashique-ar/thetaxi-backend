<?php

it('uses bounded authorized booking references for return inspection filters', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Booking/Traits/BookingSubmissionTrait.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/booking/components/return-inspection-management/return-inspection-management.component.html'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/booking/components/return-inspection-management/return-inspection-management.component.ts'));
    $ongoingTemplate = file_get_contents(base_path('../portal-thetaxi/src/app/modules/booking/components/ongoing-hire-management/ongoing-hire-management.component.html'));
    $ongoingComponent = file_get_contents(base_path('../portal-thetaxi/src/app/modules/booking/components/ongoing-hire-management/ongoing-hire-management.component.ts'));

    expect($routes)->toContain("Route::get('return-inspection/options'")
        ->and($routes)->toContain("->middleware('permission:bookings.view')")
        ->and($controller)->toContain("'record_type' => 'required|string|in:customer,vehicle,driver,service_type'")
        ->and($controller)->toContain("'per_page' => 'nullable|integer|min:1|max:50'")
        ->and($controller)->toContain("'operations_queue' => 'nullable|string|in:active'")
        ->and($controller)->toContain('$this->bookingFlowService->getFilteredBookings($filters)')
        ->and($controller)->toContain("->unique('value')")
        ->and($template)->toContain('endpoint="/booking-flow/return-inspection/options"')
        ->and($template)->toContain('recordType="customer"')
        ->and($template)->toContain('recordType="vehicle"')
        ->and($template)->toContain('recordType="driver"')
        ->and($template)->toContain('recordType="service_type"')
        ->and($template)->not->toContain('<mat-label>Customer ID</mat-label>')
        ->and($template)->not->toContain('<mat-label>Vehicle ID</mat-label>')
        ->and($template)->not->toContain('<mat-label>Driver ID</mat-label>')
        ->and($component)->not->toContain('Customer: ${value.customer_id}')
        ->and($component)->not->toContain('Vehicle: ${value.vehicle_id}')
        ->and($component)->not->toContain('Driver: ${value.driver_id}')
        ->and($ongoingTemplate)->toContain('[queryParams]="serviceTypeQueryParams"')
        ->and($ongoingComponent)->toContain("serviceTypeQueryParams = { operations_queue: 'active' }")
        ->and($ongoingTemplate)->not->toContain('Service type ID or name')
        ->and($ongoingComponent)->toContain("filters.push('Service type selected')")
        ->and($ongoingComponent)->toContain('UiManagedRecordSelectComponent');
});

it('shows readable booking references in the return inspection list', function () {
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/booking/services/return-inspection.service.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/booking/components/return-inspection-management/return-inspection-management.component.html'));

    expect($service)->toContain('booking_number: row.booking_number')
        ->and($service)->toContain('reference_number: row.reference_number')
        ->and($service)->toContain('item_code: row.item_code')
        ->and($template)->toContain('inspection.reference_number || inspection.booking_number || inspection.item_code')
        ->and($template)->not->toContain('{{inspection.id}}', '{{dispute.id}}', 'Case ID')
        ->and($component)->not->toContain("'case_id'");
});
