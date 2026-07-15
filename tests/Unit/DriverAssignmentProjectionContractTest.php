<?php

uses(Tests\TestCase::class);

it('uses an allowlisted driver assignment projection without raw model serialization', function () {
    $source = file_get_contents(app_path('Services/Driver/MobileAssignmentService.php'));
    $projection = Str::between(
        $source,
        '// Driver responses are an explicit projection.',
        "\$payload['payment_type']",
    );

    expect($projection)
        ->toContain("'booking_id' => \$assignment->booking_id")
        ->toContain("'booking_item_id' => \$assignment->booking_item_id")
        ->not->toContain('$assignment->toArray()')
        ->not->toContain("'booking' =>")
        ->not->toContain("'bookingItem' =>");
});

it('keeps the documented driver assignment fields in the allowlisted projection', function () {
    $documentation = file_get_contents(public_path('docs/driver-mobile-api.openapi.json'));
    $source = file_get_contents(app_path('Services/Driver/MobileAssignmentService.php'));

    $documentedFields = [
        'id', 'driver_id', 'booking_id', 'booking_item_id', 'status', 'trip_phase',
        'trip_mode', 'destination_known', 'driver_message', 'payment_type',
        'fare_amount', 'total_amount', 'currency', 'booking_number',
        'service_type_name', 'customer_name', 'customer_phone',
        'pickup_location_label', 'dropoff_location_label', 'is_multi_stop',
        'route_stops', 'package', 'scheduled_from', 'scheduled_to',
        'trip_completed_at',
    ];

    foreach ($documentedFields as $field) {
        expect($documentation)->toContain('"'.$field.'"');
        $projectionPattern = "/(?:'".preg_quote($field, '/')."'\\s*=>|\\['".preg_quote($field, '/')."'\\]\\s*=)/";
        expect((bool) preg_match($projectionPattern, $source))->toBeTrue("Missing documented driver field: {$field}");
    }
});

it('does not expose internal pricing structures through driver pricing metrics', function () {
    $source = file_get_contents(app_path('Services/Driver/MobileAssignmentService.php'));
    $projection = Str::between(
        $source,
        'private function driverPricingMetrics(',
        'private function firstNumeric(',
    );

    expect($projection)
        ->toContain("'hire_km'")
        ->toContain("'waiting_hours'")
        ->not->toContain("'pricing_breakdown'")
        ->not->toContain("'distance_details'")
        ->not->toContain("'waiting_rate_per_hour'")
        ->not->toContain("'base_amount'")
        ->not->toContain("'total_amount'");
});

it('returns canonical assignment and stop actions from the shared trip state owner', function () {
    $assignmentSource = file_get_contents(app_path('Services/Driver/MobileAssignmentService.php'));
    $tripSource = file_get_contents(app_path('Services/Driver/TripTrackingService.php'));

    expect($assignmentSource)
        ->toContain("\$payload['allowed_actions'] = \$this->tripTrackingService->getAssignmentAllowedActions")
        ->and($tripSource)
        ->toContain('public function getAssignmentAllowedActions(')
        ->toContain("return ['accept', 'decline'];")
        ->toContain("TripPhase::PICKUP_ARRIVED => ['start']")
        ->toContain("return \$stops->isEmpty() || \$this->allStopsTerminal(\$stops) ? ['complete'] : ['stop_action'];");
});

it('scopes buffered locations to the authenticated driver active assignment', function () {
    $source = file_get_contents(app_path('Services/Driver/LocationService.php'));
    $sync = Str::between($source, 'public function syncBufferedLocations(', 'private function buildLocationDedupeKey(');

    expect($sync)
        ->toContain("'assignment_id' => \$activeAssignmentId")
        ->not->toContain("\$locationData['assignment_id'] ?? \$activeAssignmentId");
});

it('preserves actual movement but blocks route-based repricing of contractual snapshots', function () {
    $source = file_get_contents(app_path('Services/Driver/TripTrackingService.php'));
    $sync = Str::between($source, 'private function syncOpenPackageFinalPricing(', 'private function resolveOpenPackageExtraKmRate(');

    expect($sync)
        ->toContain('if ($this->hasContractualDistanceSnapshot($booking, $bookingItem))')
        ->toContain("'actual_distance' => round(\$totalDistance, 2)")
        ->toContain("'source' => 'driver_route_points'")
        ->toContain("'pricing_effect' => 'none_contractual_snapshot'")
        ->toContain('return null;');
});

it('exposes persisted operational milestones separately from contractual pricing', function () {
    $source = file_get_contents(app_path('Http/Controllers/Api/AssignmentController.php'));

    expect($source)
        ->toContain("'operational_records' => \$operationalRecords")
        ->toContain("'pricing_effect' => 'none'")
        ->toContain("'source' => 'driver_route_points'")
        ->toContain("'source' => 'driver_assignment'")
        ->toContain("'source' => 'booking_dispatch'")
        ->not->toContain("'pricing_effect' => 'reprice'");
});

it('keeps authorized actual-route viewing read-only for contractual pricing', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $source = file_get_contents(app_path('Http/Controllers/Api/AssignmentController.php'));
    $tracking = Str::between(
        $source,
        'private function buildTrackingPayload(',
        'private function mapPersistedAssignmentStops('
    );

    expect($routes)
        ->toContain("Route::middleware(['permission:bookings.view'])->group(function () {")
        ->and($routes)->toContain("Route::get('{bookingId}/details', [AssignmentController::class, 'getAssignmentDetails'])")
        ->and($routes)->toContain("->middleware('permission:bookings.view')")
        ->and($tracking)->toContain('RoutePoint::query()')
        ->and($tracking)->toContain("'source' => 'driver_route_points'")
        ->and($tracking)->toContain("'pricing_effect' => 'none'")
        ->and($tracking)->not->toContain('PricingService')
        ->and($tracking)->not->toContain('calculatePricing')
        ->and($tracking)->not->toContain('pricing_snapshot =')
        ->and($tracking)->not->toContain('pricing_breakdown =')
        ->and($tracking)->not->toContain('->update([')
        ->and($tracking)->not->toContain('->save(');
});

it('keeps driver route history ordered and every assignment lifecycle mutation owner-scoped', function () {
    $routes = file_get_contents(base_path('routes/api_driver.php'));
    $location = file_get_contents(app_path('Http/Controllers/Api/Driver/Mobile/LocationController.php'));
    $assignment = file_get_contents(app_path('Http/Controllers/Api/Driver/Mobile/AssignmentController.php'));
    $trip = file_get_contents(app_path('Http/Controllers/Api/Driver/Mobile/TripController.php'));

    expect($routes)
        ->toContain("Route::middleware(['auth:api', 'ensure.driver'])->group(function () {")
        ->and($routes)->toContain("Route::get('{id}/status', [TripController::class, 'statusForAssignment'])")
        ->and($routes)->toContain("Route::post('{id}/arrived', [TripController::class, 'pickupArrivedForAssignment'])")
        ->and($routes)->toContain("Route::post('{id}/start', [TripController::class, 'startTripForAssignment'])")
        ->and($routes)->toContain("Route::post('{id}/complete', [TripController::class, 'endTripForAssignment'])")
        ->and($location)->toContain("->where('driver_id', \$driver->id)")
        ->and($location)->toContain("->orderBy('recorded_at', 'asc')")
        ->and($assignment)->toContain("->where('driver_id', \$driver->id)")
        ->and($trip)->toContain("->where('driver_id', \$driverId)")
        ->and($trip)->toContain('$this->tripTrackingService->getTripStatus($assignment->fresh())');
});

it('builds driver navigation stops only from booked passenger route fields', function () {
    $source = file_get_contents(app_path('Services/Driver/TripTrackingService.php'));
    $builder = Str::between(
        $source,
        'private function buildRouteStopsFromBookingItem(',
        'private function makeRouteStop('
    );

    expect($builder)
        ->toContain('$bookingItem->pickup_location')
        ->toContain("\$metadata['multi_route_stop_order']")
        ->toContain('$bookingItem->dropoff_location')
        ->not->toContain('pricing_breakdown')
        ->not->toContain('pricing_snapshot')
        ->not->toContain('defined_origin')
        ->not->toContain('defined_return')
        ->not->toContain('pricing_only');
});

it('keeps supported driver app version and lifecycle endpoints compatible with OpenAPI v2.4', function () {
    $documentation = json_decode(
        file_get_contents(public_path('docs/driver-mobile-api.openapi.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $driverRoutes = file_get_contents(base_path('routes/api_driver.php'));
    $publicRoutes = file_get_contents(base_path('routes/api_public.php'));

    expect(data_get($documentation, 'info.version'))->toBe('2.4.0')
        ->and(data_get($documentation, 'paths./api/driver/version-check.post'))->toBeArray()
        ->and(data_get($documentation, 'paths./api/public/driver-mobile/version-check.post'))->toBeArray()
        ->and($driverRoutes)->toContain("Route::match(['get', 'post'], 'version-check', [AppSettingsController::class, 'versionCheck'])")
        ->and($publicRoutes)->toContain("Route::post('driver-mobile/version-check', [AppSettingsController::class, 'versionCheck'])")
        ->and($driverRoutes)->toContain("Route::get('', [AssignmentController::class, 'index'])")
        ->and($driverRoutes)->toContain("Route::get('current', [AssignmentController::class, 'current'])")
        ->and($driverRoutes)->toContain("Route::post('{id}/accept', [AssignmentController::class, 'accept'])")
        ->and($driverRoutes)->toContain("Route::post('{id}/decline', [AssignmentController::class, 'decline'])")
        ->and($driverRoutes)->toContain("Route::post('{id}/complete', [TripController::class, 'endTripForAssignment'])")
        ->and($driverRoutes)->toContain("Route::post('bulk', [LocationController::class, 'bulkUpdate'])");
});
