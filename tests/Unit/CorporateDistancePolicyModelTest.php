<?php

use App\Models\Corporate\CorporateDistancePricingPolicy;

uses(Tests\TestCase::class);

it('defaults the contractual return location to the defined origin', function () {
    $policy = new CorporateDistancePricingPolicy([
        'origin_address' => 'Contractual Base',
        'origin_latitude' => 6.9270786,
        'origin_longitude' => 79.8612430,
    ]);

    expect($policy->resolvedReturnLocation())->toBe([
        'address' => 'Contractual Base',
        'latitude' => '6.9270786',
        'longitude' => '79.8612430',
    ]);
});

it('keeps configured return coordinates distinct from the origin', function () {
    $policy = new CorporateDistancePricingPolicy([
        'origin_address' => 'Origin', 'origin_latitude' => 6.9, 'origin_longitude' => 79.8,
        'return_address' => 'Return', 'return_latitude' => 7.1, 'return_longitude' => 80.2,
    ]);

    expect($policy->resolvedReturnLocation())->toBe([
        'address' => 'Return', 'latitude' => '7.1000000', 'longitude' => '80.2000000',
    ]);
});

it('stores contractual locations separately from operational tracking fields', function () {
    $policyFields = (new CorporateDistancePricingPolicy)->getFillable();

    expect($policyFields)
        ->toContain('origin_latitude', 'return_latitude')
        ->not->toContain('driver_id', 'assignment_id', 'acceptance_position', 'trip_start_position', 'tracking_points');
});
