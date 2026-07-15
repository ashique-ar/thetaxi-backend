<?php

use App\Models\Corporate\CorporateDistancePricingPolicy;
use App\Services\CorporateContractualDistanceCalculator;
use App\Services\CorporateDistancePolicyResolver;
use App\Services\GoogleMapsService;

uses(Tests\TestCase::class);

function contractualCalculatorPolicy(array $attributes = []): CorporateDistancePricingPolicy
{
    $policy = new CorporateDistancePricingPolicy(array_replace([
        'corporate_id' => 'company-a',
        'name' => 'Contract base',
        'default_service_mode' => 'enabled',
        'origin_address' => 'Defined origin',
        'origin_latitude' => 6.9,
        'origin_longitude' => 79.8,
        'include_origin_to_pickup' => true,
        'include_dropoff_to_return' => true,
        'movement_rate_method' => 'normal_rate',
        'is_active' => true,
    ], $attributes));
    $policy->id = 'policy-a';

    return $policy;
}

function passengerJourneyPoints(): array
{
    return [
        ['address' => 'Pickup', 'latitude' => 7.0, 'longitude' => 80.0],
        ['address' => 'Drop-off', 'latitude' => 7.1, 'longitude' => 80.1],
    ];
}

it('calculates the three contractual legs only from policy and booked points', function () {
    $policy = contractualCalculatorPolicy();
    $resolver = Mockery::mock(CorporateDistancePolicyResolver::class);
    $resolver->shouldReceive('resolve')->once()->with('company-a', 'service-a')->andReturn([
        'enabled' => true, 'source' => 'company_default', 'policy' => $policy, 'override' => null, 'error' => null,
    ]);
    $maps = Mockery::mock(GoogleMapsService::class);
    $maps->shouldReceive('distanceAndDuration')->times(3)->andReturnValues([
        ['distance_km' => 10, 'duration_seconds' => 600],
        ['distance_km' => 2, 'duration_seconds' => 180],
        ['distance_km' => 12, 'duration_seconds' => 720],
    ]);

    $result = (new CorporateContractualDistanceCalculator($resolver, $maps))
        ->calculate('company-a', 'service-a', passengerJourneyPoints());

    expect($result)
        ->origin_to_pickup_distance->toBe(10.0)
        ->journey_distance->toBe(2.0)
        ->dropoff_to_return_distance->toBe(12.0)
        ->total_billable_distance->toBe(24.0)
        ->and($result['distance_policy']['coordinate_source'])->toBe('corporate_distance_policy');
});

it('leaves disabled corporate services on normal pricing without routing', function () {
    $resolver = Mockery::mock(CorporateDistancePolicyResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn([
        'enabled' => false, 'source' => 'service_override', 'policy' => null, 'override' => null, 'error' => null,
    ]);
    $maps = Mockery::mock(GoogleMapsService::class);
    $maps->shouldNotReceive('distanceAndDuration');

    $result = (new CorporateContractualDistanceCalculator($resolver, $maps))
        ->calculate('company-a', 'service-a', passengerJourneyPoints());

    expect($result)->toBeNull();
});

it('caps separate-rate movement legs without adding them to the normal journey distance', function () {
    $policy = contractualCalculatorPolicy([
        'movement_rate_method' => 'separate_rate',
        'outbound_rate' => 100,
        'return_rate' => 50,
        'maximum_outbound_km' => 5,
        'maximum_return_km' => 8,
    ]);
    $resolver = Mockery::mock(CorporateDistancePolicyResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn([
        'enabled' => true, 'source' => 'company_default', 'policy' => $policy, 'override' => null, 'error' => null,
    ]);
    $maps = Mockery::mock(GoogleMapsService::class);
    $maps->shouldReceive('distanceAndDuration')->times(3)->andReturnValues([
        ['distance_km' => 10, 'duration_seconds' => 600],
        ['distance_km' => 2, 'duration_seconds' => 180],
        ['distance_km' => 12, 'duration_seconds' => 720],
    ]);

    $result = (new CorporateContractualDistanceCalculator($resolver, $maps))
        ->calculate('company-a', 'service-a', passengerJourneyPoints());

    expect($result['pickup_distance'])->toBe(5.0)
        ->and($result['delivery_distance'])->toBe(8.0)
        ->and($result['total_distance'])->toBe(2.0)
        ->and($result['contractual_movement_charge'])->toBe(900.0);
});

it('blocks an unroutable required contractual leg instead of substituting zero', function () {
    $policy = contractualCalculatorPolicy();
    $resolver = Mockery::mock(CorporateDistancePolicyResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn([
        'enabled' => true, 'source' => 'company_default', 'policy' => $policy, 'override' => null, 'error' => null,
    ]);
    $maps = Mockery::mock(GoogleMapsService::class);
    $maps->shouldReceive('distanceAndDuration')->once()->andReturn(['distance_km' => 0, 'duration_seconds' => 0]);

    (new CorporateContractualDistanceCalculator($resolver, $maps))
        ->calculate('company-a', 'service-a', passengerJourneyPoints());
})->throws(DomainException::class, 'Unable to route defined origin to passenger pickup.');

it('allows a legitimate zero-length leg when booked and defined coordinates are identical', function () {
    $policy = contractualCalculatorPolicy();
    $resolver = Mockery::mock(CorporateDistancePolicyResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn([
        'enabled' => true, 'source' => 'company_default', 'policy' => $policy, 'override' => null, 'error' => null,
    ]);
    $maps = Mockery::mock(GoogleMapsService::class);
    $maps->shouldReceive('distanceAndDuration')->twice()->andReturnValues([
        ['distance_km' => 2, 'duration_seconds' => 180],
        ['distance_km' => 12, 'duration_seconds' => 720],
    ]);
    $points = passengerJourneyPoints();
    $points[0] = ['address' => 'Pickup at base', 'latitude' => 6.9, 'longitude' => 79.8];

    $result = (new CorporateContractualDistanceCalculator($resolver, $maps))
        ->calculate('company-a', 'service-a', $points);

    expect($result['origin_to_pickup_distance'])->toBe(0.0)
        ->and($result['total_billable_distance'])->toBe(14.0);
});

it('contains no driver, assignment, tracking, or operational position dependency', function () {
    $source = file_get_contents(app_path('Services/CorporateContractualDistanceCalculator.php'));

    expect(strtolower($source))
        ->not->toContain('driverassignment')
        ->not->toContain('routepoint')
        ->not->toContain('tracking')
        ->not->toContain('acceptance_position')
        ->not->toContain('trip_start_position');
});

it('carries contractual policy metadata into the canonical pricing snapshot result', function () {
    $source = file_get_contents(app_path('Services/BookingFlowService.php'));
    $transform = Str::between(
        $source,
        'private function transformCalculationResult(',
        'private function resolveServicePackageInfoFromParams(',
    );

    expect($transform)
        ->toContain("'distance_policy' => \$contractual['distance_policy'] ?? null")
        ->toContain("'contractual_movement_charge' => \$movementCharge ?: null")
        ->toContain("'total_billable_distance' => \$contractual['total_billable_distance']");
});
