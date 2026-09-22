<?php

use App\Services\BookingFlowService;
use App\Services\BookingLifecycleService;
use App\Models\Booking\BookingDispatch;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;


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
        'pickup_waiting_minutes' => 1,
        'hire_waiting_minutes' => 26,
        'total_waiting_minutes' => 27,
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
            'pickup_waiting_minutes' => 1.0,
            'hire_waiting_minutes' => 26.0,
            'total_waiting_minutes' => 27.0,
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

it('rejects a reversed return odometer while preserving an explicit zero-distance reading', function () {
    $service = (new ReflectionClass(BookingLifecycleService::class))->newInstanceWithoutConstructor();
    $valid = new BookingDispatch();
    $valid->setRawAttributes(['mileage_out' => 1250, 'mileage_in' => 1250]);
    $reversed = new BookingDispatch();
    $reversed->setRawAttributes(['mileage_out' => 1250, 'mileage_in' => 1249]);

    expect(invokePrivateMethod($service, 'measuredMileageDistance', [$valid]))->toBe(0.0)
        ->and(fn () => invokePrivateMethod($service, 'measuredMileageDistance', [$reversed]))
        ->toThrow(DomainException::class, 'cannot be lower');
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

it('reuses the booking snapshot exchange rate for final pricing instead of a live rate', function () {
    $service = (new ReflectionClass(BookingLifecycleService::class))->newInstanceWithoutConstructor();
    $booking = new Booking();
    $booking->setRawAttributes(['currency' => 'USD']);
    $item = new BookingItem();
    $item->setRawAttributes([
        'currency' => 'USD',
        'pricing_breakdown' => json_encode([
            'base_pricing' => [
                'original_currency' => 'LKR',
                'exchange_rate' => 0.0031,
            ],
        ]),
    ]);

    $context = invokePrivateMethod(
        $service,
        'resolveFinalPricingCurrencyContext',
        [$item, $booking]
    );

    expect($context)->toMatchArray([
        'calculation_currency' => 'LKR',
        'booking_currency' => 'USD',
        'exchange_rate' => 0.0031,
        'rate_source' => 'base_pricing_snapshot',
    ]);
});

it('preserves the original booking channel throughout final pricing', function () {
    $service = (new ReflectionClass(BookingLifecycleService::class))->newInstanceWithoutConstructor();

    $website = new Booking();
    $website->setRawAttributes(['booking_source' => 'website']);

    $dashboard = new Booking();
    $dashboard->setRawAttributes(['booking_source' => 'dashboard', 'created_from' => 'internal']);

    $snapshotPublic = new Booking();
    $snapshotPublic->setRawAttributes([
        'booking_source' => 'dashboard',
        'pricing_snapshot' => json_encode([
            'base_pricing' => ['pricing_scope' => ['pricing_context' => 'public']],
        ]),
    ]);

    $corporate = new Booking();
    $corporate->setRawAttributes([
        'booking_source' => 'website',
        'is_corporate_booking' => true,
        'corporate_account_id' => 'corporate-1',
    ]);

    expect(invokePrivateMethod($service, 'resolvePersistedBookingPricingContext', [$website]))
        ->toBe('public')
        ->and(invokePrivateMethod($service, 'resolvePersistedBookingPricingContext', [$dashboard]))
        ->toBe('portal')
        ->and(invokePrivateMethod($service, 'resolvePersistedBookingPricingContext', [$snapshotPublic]))
        ->toBe('public')
        ->and(invokePrivateMethod($service, 'resolvePersistedBookingPricingContext', [$corporate]))
        ->toBe('corporate');
});
