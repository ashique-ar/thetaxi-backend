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

it('projects corporate traveler identity and canonical nested stop contacts', function () {
    $assignment = file_get_contents(app_path('Services/Driver/MobileAssignmentService.php'));
    $tracking = file_get_contents(app_path('Services/Driver/TripTrackingService.php'));

    expect($assignment)
        ->toContain("\$payload['booking_party'] = \$bookingParty")
        ->toContain("'traveler_type' => \$travelerType")
        ->toContain("'traveler_phone' => \$travelerPhone")
        ->toContain("\$workflowData['corporate_contact']")
        ->toContain("\$booking?->employeeUser")
        ->and($tracking)
        ->toContain("'kind' => !empty(\$stop->location['employee_id'])")
        ->toContain("'name' => \$stop->location['contact_name'] ?? null")
        ->toContain("'phone' => \$stop->location['contact_phone'] ?? null")
        ->toContain("'note' => \$stop->location['contact_note'] ?? null");
});

it('hydrates primary pickup and final dropoff contacts from booking item snapshots', function () {
    $tracking = file_get_contents(app_path('Services/Driver/TripTrackingService.php'));

    expect($tracking)
        ->toContain("\$metadata['primary_pickup_contact']")
        ->toContain("\$metadata['primary_dropoff_contact']")
        ->toContain('mergeLocationContact($bookingItem->pickup_location, $primaryPickupContact)')
        ->toContain('mergeLocationContact($bookingItem->dropoff_location, $primaryDropoffContact)')
        ->toContain("'contact_phone'");
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

it('projects completed trip metrics from canonical final pricing before booking estimates', function () {
    $mobileSource = file_get_contents(app_path('Services/Driver/MobileAssignmentService.php'));
    $internalSource = file_get_contents(app_path('Http/Controllers/Api/AssignmentController.php'));
    $tripControllerSource = file_get_contents(app_path('Http/Controllers/Api/Driver/Mobile/TripController.php'));

    foreach ([$mobileSource, $internalSource] as $source) {
        $metrics = Str::between($source, 'private function buildPricingMetrics(', 'private function firstNumeric(');

        expect($metrics)
            ->toContain("\$finalPricing['audit']['inputs']")
            ->toContain("\$finalAuditInputs['duration_minutes']")
            ->toContain("\$finalAuditInputs['distance_km']")
            ->toContain("\$finalAuditInputs['waiting_minutes']")
            ->toContain("\$finalPricing['audit']['final_base']");
    }

    expect($tripControllerSource)
        ->toContain("\$summary['assignment'] = \$this->assignmentService->buildAssignmentPayload(\$completedAssignment)");
});

it('returns one complete measured and priced contract for fixed-route and open-package completions', function () {
    $trackingSource = file_get_contents(app_path('Services/Driver/TripTrackingService.php'));
    $mobileSource = file_get_contents(app_path('Services/Driver/MobileAssignmentService.php'));

    $pricingSummary = Str::between(
        $trackingSource,
        'private function resolveCanonicalFinalPricingSummary(',
        'private function syncTripEndPaymentCollection('
    );

    expect($pricingSummary)
        ->not->toContain('!$this->isOpenPackageAssignment($assignment)')
        ->toContain("final_pricing.audit")
        ->and($trackingSource)
        ->toContain("'final_pricing'              => \$this->driverCanViewPricing")
        ->toContain("'final_pricing' => \$this->driverCanViewPricing")
        ->and($mobileSource)
        ->toContain("\$payload['operational_metrics']")
        ->toContain("'included_duration_minutes'")
        ->toContain("\$finalAuditInputs['waiting_minutes'] ?? null");
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

it('uses the canonical mobile assignment owner for heartbeat and driver status feeds', function () {
    $heartbeat = file_get_contents(app_path('Http/Controllers/Api/Driver/Mobile/HeartbeatController.php'));
    $status = file_get_contents(app_path('Http/Controllers/Api/Driver/Mobile/StatusController.php'));
    $lifecycle = file_get_contents(app_path('Services/BookingLifecycleService.php'));

    expect($heartbeat)
        ->toContain('$this->assignmentService->getActiveTripAssignment($driver)')
        ->not->toContain('DriverAssignment::where')
        ->and($status)
        ->toContain('$this->assignmentService->getPendingAssignments($driver)')
        ->toContain('$this->assignmentService->getActiveTripAssignment($driver)')
        ->toContain('$this->assignmentService->reconcileCompletedBookingAssignments($driver)')
        ->not->toContain('DriverAssignment::where')
        ->and($lifecycle)
        ->toContain('A completed booking is terminal for every assignment')
        ->toContain("\$this->closeDriverAssignmentsForCompletion(");
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
    $sync = Str::between($source, 'private function recordOpenPackageTripCompletionMetrics(', 'private function hasContractualDistanceSnapshot(');

    expect($sync)
        ->toContain('$contractualDistance = $this->hasContractualDistanceSnapshot($booking, $bookingItem)')
        ->toContain("'actual_distance' => \$totalDistance !== null ? round(\$totalDistance, 2) : null")
        ->toContain("'source' => 'driver_route_points'")
        ->toContain("'pricing_effect' => \$contractualDistance")
        ->toContain("? 'none_contractual_snapshot'")
        ->not->toContain("'total_actual'")
        ->not->toContain("'open_package_final'");
});

it('blocks driver completion when canonical pricing and invoicing cannot finish', function () {
    $source = file_get_contents(app_path('Services/Driver/TripTrackingService.php'));
    $sync = Str::between(
        $source,
        'private function syncBookingLifecycleAfterDriverTripCompletion(',
        'private function resolveCanonicalFinalPricingSummary('
    );

    expect($sync)
        ->toContain("throw new \\DomainException(")
        ->toContain('Driver trip completion was stopped')
        ->not->toContain("'status' => 'completed'");
});

it('accepts persisted driver trip completion as lifecycle evidence without a dispatch', function () {
    $source = file_get_contents(app_path('Services/BookingLifecycleService.php'));
    $completion = Str::between(
        $source,
        '$driverDirectCompletion = (bool) ($completionData[\'completed_by_driver\'] ?? false);',
        '$canSkipQc ='
    );

    expect($completion)
        ->toContain('$hasCompletedDriverAssignment = $driverDirectCompletion')
        ->toContain("->where('trip_phase', TripPhase::COMPLETED->value)")
        ->toContain("->whereNotNull('trip_completed_at')")
        ->toContain('$isActiveLifecycleStatus || $hasActiveResolvedDispatch || $hasCompletedDriverAssignment');
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

it('keeps supported driver app version and lifecycle endpoints compatible with OpenAPI v2.7', function () {
    $documentation = json_decode(
        file_get_contents(public_path('docs/driver-mobile-api.openapi.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $driverRoutes = file_get_contents(base_path('routes/api_driver.php'));
    $publicRoutes = file_get_contents(base_path('routes/api_public.php'));

    expect(data_get($documentation, 'info.version'))->toBe('2.7.0')
        ->and(data_get($documentation, 'paths./api/driver/version-check.post'))->toBeArray()
        ->and(data_get($documentation, 'paths./api/public/driver-mobile/version-check.post'))->toBeArray()
        ->and($driverRoutes)->toContain("Route::get('version-check', [AppSettingsController::class, 'versionCheck'])")
        ->and($driverRoutes)->toContain("Route::post('version-check', [AppSettingsController::class, 'versionCheck'])")
        ->and($publicRoutes)->toContain("Route::post('driver-mobile/version-check', [AppSettingsController::class, 'versionCheck'])")
        ->and($driverRoutes)->toContain("Route::get('', [AssignmentController::class, 'index'])")
        ->and($driverRoutes)->toContain("Route::get('current', [AssignmentController::class, 'current'])")
        ->and($driverRoutes)->toContain("Route::post('{id}/accept', [AssignmentController::class, 'accept'])")
        ->and($driverRoutes)->toContain("Route::post('{id}/decline', [AssignmentController::class, 'decline'])")
        ->and($driverRoutes)->toContain("Route::post('{id}/complete', [TripController::class, 'endTripForAssignment'])")
        ->and($driverRoutes)->toContain("Route::post('bulk', [LocationController::class, 'bulkUpdate'])");
});

it('keeps assignment creation time separate from the scheduled trip window', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/AssignmentController.php'));
    $bookingFlow = file_get_contents(app_path('Services/BookingFlowService.php'));
    $lifecycle = file_get_contents(app_path('Services/BookingLifecycleService.php'));

    expect($controller)
        ->toContain("'label' => 'Assigned'")
        ->toContain("'source' => 'driver_assignment.created_at'")
        ->toContain("'label' => 'Assignment scheduled'")
        ->toContain("'booking_item.from_date + from_time'")
        ->and($bookingFlow)->toContain(
            "'assigned_from' => \$this->bookingDateTime(\$bookingItem->from_date, \$bookingItem->from_time)"
        )
        ->and($lifecycle)->toContain(
            "\$selectedItem?->from_time ?? \$booking->from_time"
        );
});

it('projects stable service execution capabilities and blocks self-drive driver assignments', function () {
    $projection = file_get_contents(app_path('Services/Driver/MobileAssignmentService.php'));
    $assignment = file_get_contents(app_path('Services/AssignmentService.php'));
    $bookingFlow = file_get_contents(app_path('Services/BookingFlowService.php'));
    $documentation = json_decode(
        file_get_contents(public_path('docs/driver-mobile-api.openapi.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($projection)
        ->toContain("\$payload['execution_capabilities']")
        ->toContain("'requires_driver' => \$requiresDriver")
        ->toContain("'route_mode' => \$isOpenPackage ? 'open_package' : 'fixed_route'")
        ->and($assignment)->toContain("serviceType?->type === 'self_drive'")
        ->and($bookingFlow)->toContain("serviceType?->type !== 'self_drive'")
        ->and(data_get($documentation, 'components.schemas.DriverAssignmentService.properties.code.type'))->toBe('string')
        ->and(data_get($documentation, 'components.schemas.DriverExecutionCapabilities.properties.route_mode.enum'))
        ->toBe(['fixed_route', 'open_package']);
});

it('keeps general corporate passengers distinct from employees and company-scoped', function () {
    $request = file_get_contents(app_path('Http/Requests/Corporate/StoreCorporateBookingRequest.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Corporate/CorporateBookingController.php'));
    $service = file_get_contents(app_path('Services/CorporateBookingService.php'));

    expect($request)
        ->toContain("'booking_party_mode' => ['required', 'string', 'in:employee,general']")
        ->toContain("The selected employee does not belong to your corporate")
        ->and($controller)->toContain("create_bookings_for_others")
        ->toContain("createGeneralBooking")
        ->and($service)->toContain("public function createGeneralBooking")
        ->toContain("'employee_id' => null")
        ->toContain("'customer_id' => null");
});

it('derives driver cash status from the idempotent payment ledger result', function () {
    $tracking = file_get_contents(app_path('Services/Driver/TripTrackingService.php'));

    expect($tracking)
        ->toContain('$ledgerSummary = ($this->paymentLedger ?? app(BookingPaymentLedgerService::class))->receive')
        ->toContain("\$ledgerSummary['due_amount']")
        ->not->toContain('$totalCollected = round($previouslyCollected + $collectedAmount');
});

it('hides monetary pricing from drivers unless collection is required', function () {
    $assignment = file_get_contents(app_path('Services/Driver/MobileAssignmentService.php'));
    $tracking = file_get_contents(app_path('Services/Driver/TripTrackingService.php'));

    expect($assignment)
        ->toContain("\$payload['pricing_visible'] = \$pricingVisible")
        ->toContain("if (\$pricingVisible) {")
        ->toContain("'shows_pricing' => \$pricingVisible")
        ->and($tracking)
        ->toContain('private function mapDriverPaymentSummary(')
        ->toContain("'pricing_visible' => false")
        ->toContain("'final_pricing' => \$this->driverCanViewPricing");
});
