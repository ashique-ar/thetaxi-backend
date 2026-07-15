<?php

use App\Enums\TripPhase;
use App\Http\Controllers\Api\AssignmentController;
use App\Models\Booking\BookingItem;
use App\Models\DriverAssignment;
use App\Services\AssignmentService;
use App\Services\BookingFlowService;
use App\Services\ContractualDistanceSnapshotProjector;
use App\Services\CorporateBookingService;
use App\Services\Driver\MobileAssignmentService;
use App\Services\Driver\NotificationTriggerService;
use App\Services\Driver\TripTrackingService;

uses(Tests\TestCase::class);

it('does not serialize eager-loaded internal relations into driver assignment payloads', function () {
    $assignment = new DriverAssignment([
        'id' => 'assignment-safe',
        'driver_id' => 'driver-safe',
        'booking_id' => 'booking-safe',
        'booking_item_id' => 'item-safe',
        'status' => 'active',
        'trip_phase' => TripPhase::ACCEPTED,
    ]);
    $assignment->setRelation('booking', null);
    $assignment->setRelation('bookingItem', null);
    $assignment->setRelation('stops', collect());
    $assignment->setRelation('internalPricingPolicy', collect([
        'formula' => 'secret-formula',
        'margin' => 99,
    ]));
    $assignment->setRelation('routePoints', collect([
        ['latitude' => 1, 'longitude' => 2],
    ]));

    $trip = Mockery::mock(TripTrackingService::class);
    $trip->shouldReceive('ensureAssignmentStops')->once()->with($assignment)->andReturn(collect());
    $trip->shouldReceive('mapStopsForMobile')->once()->andReturn([]);
    $trip->shouldReceive('getAssignmentAllowedActions')->once()->andReturn(['arrived']);
    $service = new MobileAssignmentService(Mockery::mock(NotificationTriggerService::class), $trip);

    $payload = $service->buildAssignmentPayload($assignment);
    $serialized = json_encode($payload);

    expect($payload)->not->toHaveKeys([
        'booking',
        'bookingItem',
        'internalPricingPolicy',
        'routePoints',
        'relations',
    ])->and($serialized)
        ->not->toContain('secret-formula')
        ->not->toContain('latitude');
});

it('does not serialize eager-loaded assignment relations into internal tracking projections', function () {
    $assignment = new DriverAssignment([
        'id' => 'assignment-internal',
        'booking_item_id' => 'item-internal',
        'status' => 'active',
        'trip_phase' => TripPhase::ACCEPTED,
    ]);
    $assignment->setRelation('bookingItem', null);
    $assignment->setRelation('stops', collect());
    $assignment->setRelation('internalPricingPolicy', collect([
        'formula' => 'internal-secret-formula',
    ]));
    $assignment->setRelation('unrelatedDriverSessions', collect([
        ['driver_id' => 'another-driver'],
    ]));

    $controller = new AssignmentController(
        Mockery::mock(AssignmentService::class),
        Mockery::mock(BookingFlowService::class),
        new ContractualDistanceSnapshotProjector(),
    );
    $method = new ReflectionMethod($controller, 'buildTrackingPayload');
    $payload = $method->invoke($controller, $assignment, null, false, 300);
    $serialized = json_encode($payload);

    expect($payload)->toHaveKeys(['live', 'assignment', 'route', 'operational_records'])
        ->and($payload)->not->toHaveKeys(['internalPricingPolicy', 'unrelatedDriverSessions'])
        ->and($serialized)->not->toContain('internal-secret-formula')
        ->and($serialized)->not->toContain('another-driver');
});

it('does not serialize eager-loaded internal relations into corporate booking projections', function () {
    $item = (new ReflectionClass(BookingItem::class))->newInstanceWithoutConstructor();
    $item->setRawAttributes([
        'id' => 'item-corporate',
        'status' => 'confirmed',
        'metadata' => json_encode(['passenger_count' => 2]),
        'pricing_breakdown' => json_encode(['pricing_scope' => 'corporate']),
    ]);
    foreach (['booking', 'serviceType', 'vehicleGroup', 'vehicle', 'driver'] as $relation) {
        $item->setRelation($relation, null);
    }
    $item->setRelation('routePoints', collect([
        ['latitude' => 1, 'longitude' => 2],
    ]));
    $item->setRelation('driverSessions', collect([
        ['device_uuid' => 'private-device'],
    ]));
    $item->setRelation('internalPricingPolicy', collect([
        'formula' => 'corporate-secret-formula',
    ]));

    $service = app(CorporateBookingService::class);
    $method = new ReflectionMethod($service, 'mapBookingItem');
    $payload = $method->invoke($service, $item, true);
    $serialized = json_encode($payload);

    expect($payload)->not->toHaveKeys([
        'routePoints',
        'driverSessions',
        'internalPricingPolicy',
        'metadata',
        'pricing_breakdown',
    ])->and($serialized)
        ->not->toContain('private-device')
        ->not->toContain('corporate-secret-formula')
        ->not->toContain('latitude');
});
