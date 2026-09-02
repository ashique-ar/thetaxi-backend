<?php

use App\Http\Requests\Driver\Mobile\EndTripRequest;
use Illuminate\Support\Facades\Validator;

uses(Tests\TestCase::class);

it('requires genuine final coordinates for normal completion', function () {
    $request = new EndTripRequest();
    $validator = Validator::make([], $request->rules());

    expect($validator->errors()->keys())->toContain('latitude', 'longitude');
});

it('allows acknowledged emergency completion without invented coordinates', function () {
    $request = new EndTripRequest();
    $validator = Validator::make([
        'tracking_unavailable_acknowledged' => true,
        'tracking_outage_kind' => 'gps_and_data',
        'tracking_unavailable_reason' => 'Location and mobile data were unavailable at drop-off.',
        'client_recorded_at' => '2026-09-02T10:30:00Z',
    ], $request->rules());

    expect($validator->passes())->toBeTrue();
});

it('persists incomplete evidence separately without changing pricing', function () {
    $service = file_get_contents(app_path('Services/Driver/TripTrackingService.php'));
    $observability = file_get_contents(app_path('Services/BookingObservabilityService.php'));
    $health = file_get_contents(app_path('Services/BookingOperationsHealthMonitor.php'));

    expect($service)
        ->toContain("'status' => \$trackingUnavailable ? 'incomplete' : 'recorded'")
        ->toContain("'pricing_effect' => 'none'")
        ->toContain("'operations_review_required' => \$trackingUnavailable")
        ->toContain("\$routeEvidence['completion'] = \$evidence")
        ->and($observability)->toContain("'completion_evidence'")
        ->and($health)->toContain('booking_tracking_completion_evidence_incomplete');
});
