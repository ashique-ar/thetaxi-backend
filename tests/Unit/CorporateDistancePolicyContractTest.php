<?php

use App\Http\Requests\Corporate\PreviewCorporateDistancePolicyRequest;
use App\Http\Requests\Corporate\UpsertCorporateDistancePolicyRequest;
use App\Http\Requests\Corporate\UpsertCorporateServiceDistancePolicyRequest;
use App\Http\Resources\Corporate\CorporateDistancePolicyResource;
use App\Http\Resources\Corporate\CorporateServiceDistancePolicyResource;
use App\Models\Corporate\CorporateDistancePricingPolicy;
use App\Models\Corporate\CorporateServiceDistancePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;


function validCorporateDistancePolicyPayload(): array
{
    return [
        'name' => 'Main contractual base',
        'default_service_mode' => 'enabled',
        'origin_address' => 'Colombo Base',
        'origin_latitude' => 6.9270786,
        'origin_longitude' => 79.8612430,
        'include_origin_to_pickup' => true,
        'include_dropoff_to_return' => true,
        'movement_rate_method' => 'normal_rate',
        'is_active' => true,
    ];
}

it('validates contractual coordinates and supported modes', function () {
    $formRequest = new UpsertCorporateDistancePolicyRequest;
    $validator = Validator::make(validCorporateDistancePolicyPayload(), $formRequest->rules());

    expect($validator->passes())->toBeTrue();

    $invalid = Validator::make(
        array_replace(validCorporateDistancePolicyPayload(), ['default_service_mode' => 'inherit']),
        $formRequest->rules(),
    );
    expect($invalid->fails())->toBeTrue();
});

it('requires explicit movement rates for separate-rate policies', function () {
    $payload = validCorporateDistancePolicyPayload();
    $payload['movement_rate_method'] = 'separate_rate';

    $validator = Validator::make($payload, (new UpsertCorporateDistancePolicyRequest)->rules());

    expect($validator->errors()->keys())->toContain('outbound_rate', 'return_rate');
});

it('requires complete return coordinates instead of silently accepting partial locations', function () {
    $payload = validCorporateDistancePolicyPayload();
    $payload['return_address'] = 'Different Return Base';
    $payload['return_latitude'] = 7.1;

    $validator = Validator::make($payload, (new UpsertCorporateDistancePolicyRequest)->rules());

    expect($validator->errors()->keys())->toContain('return_longitude');
});

it('exposes only contractual policy fields from the policy resource', function () {
    $policy = new CorporateDistancePricingPolicy(validCorporateDistancePolicyPayload() + [
        'id' => '11111111-1111-1111-1111-111111111111',
        'corporate_id' => '22222222-2222-2222-2222-222222222222',
        'is_default' => true,
    ]);

    $payload = (new CorporateDistancePolicyResource($policy))->toArray(Request::create('/'));

    expect($payload)
        ->toHaveKeys(['defined_origin', 'defined_return', 'movement_rate_method'])
        ->not->toHaveKeys([
            'driver', 'driver_id', 'assignment', 'tracking_points', 'route_history',
            'acceptance_position', 'dispatch_position', 'trip_start_position', 'trip_end_position',
        ]);
});

it('accepts only the three explicit service application modes', function () {
    $rules = (new UpsertCorporateServiceDistancePolicyRequest)->rules();

    foreach (['inherit', 'enabled', 'disabled'] as $mode) {
        $validator = Validator::make([
            'application_mode' => $mode,
            'is_active' => true,
        ], $rules);

        expect($validator->passes())->toBeTrue();
    }

    $invalid = Validator::make([
        'application_mode' => 'driver_position',
        'is_active' => true,
    ], $rules);

    expect($invalid->fails())->toBeTrue();
});

it('allowlists service override fields without operational data', function () {
    $override = new CorporateServiceDistancePolicy([
        'id' => '33333333-3333-3333-3333-333333333333',
        'application_mode' => 'enabled',
        'origin_location_override' => [
            'address' => 'Service base',
            'latitude' => 6.9,
            'longitude' => 79.8,
        ],
        'is_active' => true,
    ]);

    $payload = (new CorporateServiceDistancePolicyResource($override))
        ->toArray(Request::create('/'));

    expect($payload)
        ->toHaveKeys(['application_mode', 'origin_location_override', 'movement_rate_method'])
        ->not->toHaveKeys([
            'corporate_id', 'service_type_id', 'driver_id', 'assignment_id',
            'tracking_points', 'route_history', 'actual_distance',
        ]);
});

it('requires explicit rates for a separate-rate service override', function () {
    $validator = Validator::make([
        'application_mode' => 'enabled',
        'movement_rate_method' => 'separate_rate',
        'is_active' => true,
    ], (new UpsertCorporateServiceDistancePolicyRequest)->rules());

    expect($validator->errors()->keys())->toContain('outbound_rate', 'return_rate');
});

it('requires coordinate-backed passenger points for contractual preview', function () {
    $rules = (new PreviewCorporateDistancePolicyRequest)->rules();
    expect($rules['service_type_id'])->toContain('uuid', 'exists:service_types,id')
        ->and($rules['vehicle_group_id'])->toContain('uuid', 'exists:vehicle_groups,id');

    $validator = Validator::make([
        'pickup' => ['address' => 'Pickup', 'latitude' => null, 'longitude' => 79.8],
        'dropoff' => ['address' => 'Drop-off', 'latitude' => 6.9],
    ], collect($rules)->only([
        'pickup', 'pickup.address', 'pickup.latitude', 'pickup.longitude',
        'dropoff', 'dropoff.address', 'dropoff.latitude', 'dropoff.longitude',
    ])->all());

    expect($validator->errors()->keys())->toContain(
        'pickup.latitude',
        'dropoff.longitude',
    );
});

it('previews through canonical pricing with an allowlisted non-operational response', function () {
    $source = file_get_contents(app_path('Http/Controllers/Api/Corporate/CorporateDistancePolicyController.php'));
    $preview = Str::between($source, 'public function preview(', 'public function updateService(');

    expect($preview)
        ->toContain('$this->bookingFlow->calculateDynamicPricing([')
        ->toContain("'corporate_account_id' => \$corporate->id")
        ->toContain("'pickup_location' => \$data['pickup']")
        ->toContain("'dropoff_location' => \$data['dropoff']")
        ->toContain("'origin_to_pickup_distance'")
        ->toContain("'total_billable_distance'")
        ->not->toContain('driver_id')
        ->not->toContain('assignment_id')
        ->not->toContain('tracking');
});

it('labels expired and incomplete policies in the server projection', function () {
    $expired = new CorporateDistancePricingPolicy(validCorporateDistancePolicyPayload() + [
        'effective_until' => now()->subDay(),
    ]);
    $expiredPayload = (new CorporateDistancePolicyResource($expired))
        ->toArray(Request::create('/'));

    $incompleteOverride = new CorporateServiceDistancePolicy([
        'application_mode' => 'enabled',
        'movement_rate_method' => 'separate_rate',
        'outbound_rate' => null,
        'return_rate' => null,
        'is_active' => true,
    ]);
    $overridePayload = (new CorporateServiceDistancePolicyResource($incompleteOverride))
        ->toArray(Request::create('/'));

    expect($expiredPayload['configuration_status'])->toBe('expired')
        ->and($expiredPayload['warning'])->toContain('expired')
        ->and($overridePayload['configuration_status'])->toBe('incomplete')
        ->and($overridePayload['warning'])->toContain('require outbound and return rates');
});

it('keeps the latest saved override visible when it is not currently effective', function () {
    $source = file_get_contents(app_path('Services/CorporateDistancePolicyService.php'));
    $listing = Str::between($source, 'public function servicePolicies(', 'public function saveServicePolicy(');

    expect($listing)
        ->toContain('$latestOverride = $corporate->serviceDistancePolicies()')
        ->toContain("'application_mode' => \$latestOverride?->application_mode ?? 'inherit'")
        ->toContain("'configuration_status' => \$overrideProjection['configuration_status']")
        ->toContain("'warning' => \$resolved['error']");
});
