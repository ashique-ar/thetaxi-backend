<?php


it('accepts and projects persisted item-scoped QC findings', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Booking/BookingLifecycleController.php'));
    $service = file_get_contents(app_path('Services/BookingLifecycleService.php'));

    expect($controller)
        ->toContain("'booking_item_id' => 'nullable|string'")
        ->toContain("'damages_found.*.location' => 'required_with:damages_found|string|max:255'")
        ->toContain("'issues_reported.*.severity' => 'required_with:issues_reported|in:low,medium,high'");

    expect($service)
        ->toContain("'damages_found' => \$qc->damages_found")
        ->toContain("'issues_reported' => \$qc->issues_reported");
});

it('keeps maintenance-required vehicles out of the available pool after QC', function () {
    $service = file_get_contents(app_path('Services/BookingLifecycleService.php'));
    $completion = Str::between(
        $service,
        'public function completeQCInspection(',
        'public function completeRepairs('
    );

    expect($completion)
        ->toContain('!$qc->needsRepair() && !$qc->requires_maintenance')
        ->toContain('VehicleAvailabilityStatus::UNAVAILABLE_MAINTENANCE->value');
});

it('keeps lifecycle actions and blockers authoritative for completion', function () {
    $service = file_get_contents(app_path('Services/BookingLifecycleService.php'));

    expect($service)
        ->toContain("'allowed_actions' => \$allowedActions")
        ->toContain("'blocking_reasons' => \$blockingReasons")
        ->toContain('assertItemSafeLifecycle($booking, $bookingItemId)');
});

it('projects canonical vehicle availability on item-scoped booking surfaces', function () {
    $assignmentController = file_get_contents(app_path('Http/Controllers/Api/AssignmentController.php'));
    $bookingFlow = file_get_contents(app_path('Services/BookingFlowService.php'));

    expect($assignmentController)
        ->toContain("'selected_booking_item_id' => \$selectedBookingItem?->id")
        ->toContain("'availability_status' => \$selectedVehicle->availability_status");

    expect($bookingFlow)
        ->toContain("'availability_status' => \$itemVehicle->availability_status");
});

it('keeps scheduled and overdue returns actionable through the lifecycle contract', function () {
    $service = file_get_contents(app_path('Services/BookingLifecycleService.php'));
    $actions = Str::between(
        $service,
        'private function resolveAllowedLifecycleActions(',
        'private function getStageProgress('
    );

    expect($actions)
        ->toContain('BookingLifecycleStatus::RETURN_SCHEDULED')
        ->toContain('BookingLifecycleStatus::RETURN_OVERDUE')
        ->toContain("\$actions[] = 'process_return'")
        ->toContain("\$blockingReasons[] = 'QC repair must be completed'")
        ->toContain("\$blockingReasons[] = 'Booking is cancelled'");
});

it('closes item-scoped driver assignments and sessions on direct return completion', function () {
    $service = file_get_contents(app_path('Services/BookingLifecycleService.php'));
    $returnFlow = Str::between(
        $service,
        'public function processReturn(',
        '// ========================\n    // QC STAGE'
    );
    $assignmentCleanup = Str::between(
        $service,
        'private function closeDriverAssignmentsForCompletion(',
        'private function completeMultiItemBookingItem('
    );

    expect($returnFlow)
        ->toContain('$this->closeDriverAssignmentsForCompletion(')
        ->toContain('$bookingItem?->id ? (string) $bookingItem->id : null')
        ->toContain('$this->finalizeMultiItemBookingIfReady(');

    expect($assignmentCleanup)
        ->toContain("->when(\$bookingItemId, fn (\$query) => \$query->where('booking_item_id', \$bookingItemId))")
        ->toContain("->update(['assignment_id' => null])");
});
