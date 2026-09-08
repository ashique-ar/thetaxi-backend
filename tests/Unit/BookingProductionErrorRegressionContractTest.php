<?php

use Illuminate\Support\Str;

it('does not calculate route pricing until required locations are usable', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/BookingFlowService.php');
    $availability = Str::between(
        $source,
        'public function getAvailableVehicleGroups(',
        'public function searchSpecificVehicles('
    );

    expect($source)
        ->toContain("sanitizeCanonicalLocationParam(\$params, 'pickup_location')")
        ->toContain("sanitizeCanonicalLocationParam(\$params, 'dropoff_location')")
        ->and($availability)
        ->toContain('$hasRequiredPricingLocations')
        ->toContain('if ($serviceTypeModel && $hasRequiredPricingLocations)');
});

it('does not require pricing context for vehicle conflict availability', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/Api/Booking/Traits/BookingAvailabilityTrait.php');
    $service = file_get_contents(dirname(__DIR__, 2) . '/app/Services/BookingFlowService.php');
    $search = Str::between(
        $service,
        'public function searchSpecificVehicles(',
        'public function searchSpecificDrivers('
    );

    expect($source)
        ->toContain("'service_type' => 'nullable|string'")
        ->toContain("'service_type_id' => 'nullable|string'")
        ->and($search)
        ->not->toContain("\$params['service_type']")
        ->not->toContain("\$params['service_type_id']");
});

it('uses authoritative vehicle enforcement consistently in group and specific availability', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/BookingFlowService.php');
    $groupAnalysis = Str::between(
        $source,
        'private function analyzeVehicleAvailability(',
        'private function getVehicleConflictsDetailed('
    );
    $specificSearch = Str::between(
        $source,
        'public function searchSpecificVehicles(',
        'public function searchSpecificDrivers('
    );

    expect($groupAnalysis)
        ->toContain('getEnhancedVehicleAvailability(')
        ->toContain("['enforcement']['blocking_reasons']")
        ->and($specificSearch)
        ->toContain("->where('status', 'active')")
        ->toContain("'blocking_reasons' => \$availability['enforcement']['blocking_reasons'] ?? []")
        ->toContain("['available', 'available_concurrent']");
});

it('returns validation and missing-booking responses without converting them to server errors', function () {
    $submission = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/Api/Booking/Traits/BookingSubmissionTrait.php');
    $assignment = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/Api/AssignmentController.php');

    expect($submission)
        ->toContain('catch (\\Illuminate\\Validation\\ValidationException $e)')
        ->and($assignment)
        ->toContain('catch (ModelNotFoundException $e)')
        ->toContain("'message' => 'Booking not found'");
});

it('preserves booking item identities during booking updates', function () {
    $service = file_get_contents(dirname(__DIR__, 2) . '/app/Services/BookingFlowService.php');
    $update = Str::between(
        $service,
        'public function updateBooking(',
        'private function generateConflictRecommendations('
    );
    $draft = Str::between(
        $service,
        'public function saveBookingDraft(',
        'private function hasDraftPricingInputs('
    );

    expect($update)
        ->toContain('BookingItem::withTrashed()')
        ->toContain('$bookingItem->restore()')
        ->toContain('$bookingItem->update($itemAttributes)')
        ->toContain("->whereNotIn('id', \$retainedBookingItemIds)")
        ->not->toContain('$booking->bookingItems()->delete();')
        ->and($draft)
        ->toContain('BookingItem::withTrashed()')
        ->toContain('$existingBookingItems->get($submittedItemId)')
        ->not->toContain('$booking->bookingItems()->delete();');
});

it('recovers booking management links that reference a replaced item', function () {
    $controller = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/Api/AssignmentController.php');

    expect($controller)
        ->toContain('BookingItem::withTrashed()')
        ->toContain("'selection_warning' => \$selectionWarning")
        ->toContain('The current trip has been opened.');
});

it('promotes the primary corporate passenger so bookings always resolve a customer', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/BookingFlowService.php');
    $normalization = Str::between(
        $source,
        'public function normalizeCorporateEmployeeReferences(',
        'public function isValidBookingEmployeeId('
    );

    expect($normalization)
        ->toContain("data_get(\$item, 'metadata.primary_pickup_contact.employee_id')")
        ->toContain("'corporate_id'")
        ->toContain("\$params['employee_id'] = (string) \$employee->user_id");

    expect($source)
        ->toContain("if (\$isCorporateBooking && Auth::id())")
        ->toContain("return \$this->ensureCustomerForUser((string) Auth::id())");
});
