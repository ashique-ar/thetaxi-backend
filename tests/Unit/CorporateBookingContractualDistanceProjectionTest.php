<?php

use App\Services\ContractualDistanceSnapshotProjector;
use App\Services\InvoiceService;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;

it('allowlists immutable contractual snapshot fields for corporate details', function () {
    $result = (new ContractualDistanceSnapshotProjector)->project([
        'base_pricing' => [
            'distance_policy' => [
                'source' => 'service_override',
                'policy_id' => 'private-policy-id',
                'policy_name' => 'Airport contract',
                'mode' => 'enabled',
                'rate_method' => 'normal_rate',
                'coordinate_source' => 'corporate_distance_policy',
                'defined_origin' => ['address' => 'Depot', 'latitude' => '6.9000', 'longitude' => '79.8500', 'internal' => 'hidden'],
                'defined_return' => ['address' => 'Depot', 'latitude' => '6.9000', 'longitude' => '79.8500'],
                'calculated_at' => '2026-07-15T10:00:00Z',
            ],
            'distance_details' => [
                'origin_to_pickup_distance' => 10,
                'journey_distance' => 2,
                'dropoff_to_return_distance' => 12,
                'total_billable_distance' => 24,
                'actual_route_distance' => 99,
            ],
            'contractual_movement_charge' => 450,
            'calculation_metadata' => ['formula' => 'private'],
        ],
        'route_points' => [['latitude' => 1, 'longitude' => 2]],
    ]);

    expect($result)->toBe([
        'source' => 'service_override',
        'policy_name' => 'Airport contract',
        'mode' => 'enabled',
        'rate_method' => 'normal_rate',
        'defined_origin' => ['address' => 'Depot', 'latitude' => 6.9, 'longitude' => 79.85],
        'defined_return' => ['address' => 'Depot', 'latitude' => 6.9, 'longitude' => 79.85],
        'origin_to_pickup_distance' => 10.0,
        'journey_distance' => 2.0,
        'dropoff_to_return_distance' => 12.0,
        'total_billable_distance' => 24.0,
        'movement_charge' => 450.0,
        'calculated_at' => '2026-07-15T10:00:00Z',
    ])->not->toHaveKeys(['policy_id', 'route_points', 'actual_route_distance', 'calculation_metadata']);
});

it('does not project normal or operational distance data as contractual pricing', function () {
    expect((new ContractualDistanceSnapshotProjector)->project([
        'distance_policy' => ['coordinate_source' => 'driver_route_points'],
        'distance_details' => ['total_billable_distance' => 15],
    ]))->toBeNull();
});

it('adds only stable policy audit fields to the internal snapshot projection', function () {
    $result = (new ContractualDistanceSnapshotProjector)->projectForInternal([
        'distance_policy' => [
            'source' => 'service_override',
            'policy_id' => 'policy-1',
            'policy_name' => 'Admin review policy',
            'coordinate_source' => 'corporate_distance_policy',
            'include_origin_to_pickup' => true,
            'include_dropoff_to_return' => true,
            'maximum_outbound_km' => '20.5',
            'maximum_return_km' => null,
        ],
        'distance_details' => ['total_billable_distance' => 30],
        'calculation_metadata' => ['variables_used' => ['driver_latitude' => 1]],
        'route_points' => [['latitude' => 1, 'longitude' => 2]],
    ]);

    expect($result)
        ->policy_id->toBe('policy-1')
        ->coordinate_source->toBe('corporate_distance_policy')
        ->maximum_outbound_km->toBe(20.5)
        ->and($result)->not->toHaveKeys(['calculation_metadata', 'route_points']);
});

it('builds customer invoice breakdowns from saved item snapshots without operational data', function () {
    $item = (new ReflectionClass(BookingItem::class))->newInstanceWithoutConstructor();
    $item->setRawAttributes(['pricing_breakdown' => json_encode([
        'distance_policy' => [
            'source' => 'company_default',
            'policy_name' => 'Corporate base',
            'mode' => 'enabled',
            'rate_method' => 'normal_rate',
            'coordinate_source' => 'corporate_distance_policy',
            'defined_origin' => ['address' => 'Base', 'latitude' => 6.9, 'longitude' => 79.8],
            'defined_return' => ['address' => 'Base', 'latitude' => 6.9, 'longitude' => 79.8],
        ],
        'distance_details' => [
            'origin_to_pickup_distance' => 5,
            'journey_distance' => 3,
            'dropoff_to_return_distance' => 6,
            'total_billable_distance' => 14,
            'driver_actual_distance' => 18,
        ],
    ])]);
    $item->setRelation('serviceType', null);

    $booking = (new ReflectionClass(Booking::class))->newInstanceWithoutConstructor();
    $booking->setRelation('bookingItems', collect([$item]));

    $service = new InvoiceService(new ContractualDistanceSnapshotProjector);
    $method = new ReflectionMethod($service, 'contractualDistanceBreakdowns');
    $result = $method->invoke($service, $booking);

    expect($result)->toHaveCount(1)
        ->and($result[0]['total_billable_distance'])->toBe(14.0)
        ->and($result[0])->not->toHaveKey('driver_actual_distance');
});

it('keeps contractual distance breakdowns hidden from invoice PDF and email presentation', function () {
    $resources = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views';
    $invoiceTemplate = file_get_contents($resources . DIRECTORY_SEPARATOR . 'invoices' . DIRECTORY_SEPARATOR . 'invoice.blade.php');
    $emailTemplate = file_get_contents($resources . DIRECTORY_SEPARATOR . 'emails' . DIRECTORY_SEPARATOR . 'invoice.blade.php');

    expect($invoiceTemplate)
        ->toContain('Contractual distance breakdown intentionally hidden from presentation.')
        ->not->toContain('Agreed Contractual Distance')
        ->and($emailTemplate)
        ->toContain('Contractual distance breakdown intentionally hidden from presentation.')
        ->not->toContain('Agreed Contractual Distance');
});
