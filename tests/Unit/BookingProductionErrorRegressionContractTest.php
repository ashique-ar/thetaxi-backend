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

it('accepts either canonical service type key for vehicle availability', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/Api/Booking/Traits/BookingAvailabilityTrait.php');

    expect($source)
        ->toContain("'service_type' => 'required_without:service_type_id|string'")
        ->toContain("'service_type_id' => 'required_without:service_type|string'");
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
