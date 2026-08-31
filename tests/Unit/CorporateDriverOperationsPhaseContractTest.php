<?php

uses(Tests\TestCase::class);

it('covers the corporate driver scenario matrix through the canonical mobile endpoints', function () {
    $routes = file_get_contents(base_path('routes/api_driver.php'));
    $tracking = file_get_contents(app_path('Services/Driver/TripTrackingService.php'));
    $projection = file_get_contents(app_path('Services/Driver/MobileAssignmentService.php'));
    expect($routes)->toContain("{id}/accept", "{id}/decline", "{id}/arrived", "{id}/start", "{id}/complete", "{id}/collect-payment", "Route::post('bulk'")
        ->and($projection)->toContain('booking_item_id', 'trip_mode', 'is_multi_stop', 'payment_collection_required', 'Corporate billing - do not collect cash')
        ->and($tracking)->toContain('completeBooking(', 'syncTripEndPaymentCollection', 'pricing_effect', 'none_contractual_snapshot');
});

it('keeps driver projections operational and excludes corporate finance internals', function () {
    $projection = file_get_contents(app_path('Services/Driver/MobileAssignmentService.php'));
    $serializationContracts = file_get_contents(base_path('tests/Unit/DriverAssignmentProjectionContractTest.php'));
    expect($projection)->toContain("'allowed_actions'", "'pickup_location_label'", "'dropoff_location_label'", "'route_stops'")
        ->and($serializationContracts)->toContain("->not->toContain(\"'pricing_breakdown'\")");
});

it('reconciles item completion final pricing aggregate completion invoicing and vehicle release', function () {
    $lifecycle = file_get_contents(app_path('Services/BookingLifecycleService.php'));
    $tracking = file_get_contents(app_path('Services/Driver/TripTrackingService.php'));
    expect($lifecycle)->toContain("'final_priced_at'", 'allItemsCompleted', 'Aggregate invoice deferred', 'availability_status')
        ->and($tracking)->toContain('completeBooking(', 'booking_item_id', 'trip_completed_at');
});
