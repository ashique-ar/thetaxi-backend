<?php

use App\Services\BookingFlowService;
use App\Services\BookingLifecycleService;

uses(Tests\TestCase::class);

function invokePrivateMethod(object $target, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($target, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($target, $arguments);
}

it('keeps final pricing dates times minutes stops package allowance and operational charges together', function () {
    $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();

    $inputs = invokePrivateMethod($service, 'prepareCalculationInputs', [[
        'vehicle_group_id' => 'group-1',
        'duration_minutes' => 95,
        'from_date' => '2026-07-18',
        'to_date' => '2026-07-18',
        'from_time' => '08:35',
        'to_time' => '10:10',
        'additional_stops' => 2,
        'stops' => 2,
        'package_included_km' => 120,
        'manual_additional_charge' => 450.50,
        'late_return_fee' => 125,
    ]]);

    expect($inputs)
        ->toMatchArray([
            'duration_minutes' => 95.0,
            'duration_hours' => 95 / 60,
            'from_date' => '2026-07-18',
            'to_date' => '2026-07-18',
            'from_time' => '08:35',
            'to_time' => '10:10',
            'additional_stops' => 2.0,
            'stops' => 2.0,
            'package_included_km' => 120.0,
            'manual_additional_charge' => 450.50,
            'late_return_fee' => 125.0,
        ])
        ->and($inputs)->toHaveKeys(['is_weekend', 'is_holiday', 'month', 'day_of_week']);
});

it('derives stop count and included kilometres from persisted booking metadata', function () {
    $service = (new ReflectionClass(BookingLifecycleService::class))->newInstanceWithoutConstructor();

    $stops = invokePrivateMethod($service, 'resolveBookedAdditionalStops', [[
        'multi_route_stop_order' => [
            ['route_order' => 1, 'location' => ['address' => 'A']],
            ['route_order' => 2, 'location' => ['address' => 'B']],
        ],
        // Ordered stops are canonical and must not be added to legacy copies.
        'multi_pickup_locations' => [['address' => 'A']],
    ]]);
    $includedKm = invokePrivateMethod($service, 'resolvePackageIncludedKilometres', [
        ['package_info' => ['max_km_per_day' => 80]],
        [],
        1500,
    ]);

    expect($stops)->toBe(2)
        ->and($includedKm)->toBe(160.0);
});

it('recognizes exact operational variables so charges can never be appended twice', function () {
    $service = (new ReflectionClass(BookingLifecycleService::class))->newInstanceWithoutConstructor();

    expect(invokePrivateMethod(
        $service,
        'formulaReferencesAny',
        ['base_rate + manual_additional_charge + late_return_fee', ['manual_additional_charge']]
    ))->toBeTrue()
        ->and(invokePrivateMethod(
            $service,
            'formulaReferencesAny',
            ['base_rate + manual_additional_chargeable', ['manual_additional_charge']]
        ))->toBeFalse();
});

it('retains matched slab details in final calculation results for the invoice audit example', function () {
    $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();

    $result = invokePrivateMethod($service, 'transformCalculationResult', [[
        'total_amount' => 1500,
        'total_amount_without_customizations' => 1500,
        'breakdown' => [],
        'slab_info' => ['id' => 'slab-1', 'name' => '90 to 180 minutes'],
        'definition_id' => 'definition-1',
    ], [
        'vehicle_group_id' => 'group-1',
    ], 'final_calculation', null]);

    expect($result['slab_information'])->toMatchArray([
        'id' => 'slab-1',
        'name' => '90 to 180 minutes',
    ]);
});
