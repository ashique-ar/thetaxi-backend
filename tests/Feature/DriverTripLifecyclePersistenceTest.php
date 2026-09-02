<?php

use App\Enums\TripPhase;
use App\Models\Driver\Driver;
use App\Models\Driver\DriverSession;
use App\Models\Driver\RoutePoint;
use App\Models\DriverAssignment;
use App\Models\DriverAssignmentStop;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingDispatch;
use App\Models\Booking\BookingItem;
use App\Models\Corporate\CorporateDistancePricingPolicy;
use App\Services\AssignmentService;
use App\Services\AvailabilityEnforcementService;
use App\Services\BookingLifecycleService;
use App\Services\BookingFlowService;
use App\Services\ContractualDistanceSnapshotProjector;
use App\Services\CorporateContractualDistanceCalculator;
use App\Services\CorporateDistancePolicyResolver;
use App\Services\CurrencyService;
use App\Services\Driver\MobileAssignmentService;
use App\Services\Driver\LocationService;
use App\Services\Driver\NotificationTriggerService;
use App\Services\Driver\TripTrackingService;
use App\Services\Driver\WaitingTimeService;
use App\Services\GoogleMapsService;
use App\Services\InvoiceService;
use App\Services\MailDispatchService;
use App\Services\PricingVariableService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    activity()->disableLogging();
    Schema::dropIfExists('route_points');
    Schema::dropIfExists('driver_assignment_stops');
    Schema::dropIfExists('driver_assignments');
    Schema::dropIfExists('drivers');
    Schema::dropIfExists('driver_sessions');
    Schema::dropIfExists('booking_items');
    Schema::dropIfExists('bookings');
    Schema::dropIfExists('booking_dispatches');
    Schema::dropIfExists('booking_approvals');
    Schema::dropIfExists('corporate_service_distance_policies');
    Schema::dropIfExists('corporate_distance_pricing_policies');

    Schema::create('bookings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('booking_number')->nullable();
        $table->string('confirmation_number')->nullable();
        $table->string('status')->default('confirmed');
        $table->timestamp('completed_at')->nullable();
        $table->uuid('vehicle_id')->nullable();
        $table->uuid('corporate_account_id')->nullable();
        $table->json('workflow_data')->nullable();
        $table->json('pricing_snapshot')->nullable();
        $table->json('distance_metrics')->nullable();
        $table->json('duration_metrics')->nullable();
        $table->decimal('actual_distance', 10, 2)->nullable();
        $table->integer('actual_duration')->nullable();
        $table->decimal('total_estimated', 12, 2)->nullable();
        $table->decimal('total_actual', 12, 2)->nullable();
        $table->decimal('amount_to_pay', 12, 2)->nullable();
        $table->string('currency')->nullable();
        $table->string('payment_responsibility')->nullable();
        $table->string('payment_collection_method')->nullable();
        $table->string('payment_collection_status')->nullable();
        $table->string('payment_method')->nullable();
        $table->string('payment_type')->nullable();
        $table->string('payment_status')->nullable();
        $table->string('payment_arrangement_status')->nullable();
        $table->string('customer_settlement_status')->nullable();
        $table->string('corporate_settlement_status')->nullable();
        $table->string('driver_collection_status')->nullable();
        $table->string('invoice_status')->nullable();
        $table->string('refund_status')->nullable();
        $table->date('settlement_due_date')->nullable();
        $table->timestamp('settled_at')->nullable();
        $table->decimal('payment_collected_amount', 12, 2)->nullable();
        $table->timestamp('payment_collected_at')->nullable();
        $table->uuid('payment_collected_by_driver_id')->nullable();
        $table->text('payment_notes')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('booking_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->uuid('vehicle_id')->nullable();
        $table->uuid('vehicle_group_id')->nullable();
        $table->json('metadata')->nullable();
        $table->json('pricing_breakdown')->nullable();
        $table->json('pickup_location')->nullable();
        $table->decimal('pickup_latitude', 10, 7)->nullable();
        $table->decimal('pickup_longitude', 10, 7)->nullable();
        $table->json('dropoff_location')->nullable();
        $table->decimal('dropoff_latitude', 10, 7)->nullable();
        $table->decimal('dropoff_longitude', 10, 7)->nullable();
        $table->decimal('unit_price', 12, 2)->nullable();
        $table->decimal('total_price', 12, 2)->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('booking_dispatches', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->string('dispatch_status')->nullable();
        $table->timestamp('dispatched_at')->nullable();
        $table->timestamp('actual_return_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('booking_approvals', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->string('status');
        $table->timestamp('approved_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('corporate_distance_pricing_policies', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('corporate_id');
        $table->string('name');
        $table->boolean('is_default')->default(false);
        $table->string('default_service_mode')->default('disabled');
        $table->text('origin_address');
        $table->decimal('origin_latitude', 10, 7);
        $table->decimal('origin_longitude', 10, 7);
        $table->text('return_address')->nullable();
        $table->decimal('return_latitude', 10, 7)->nullable();
        $table->decimal('return_longitude', 10, 7)->nullable();
        $table->boolean('include_origin_to_pickup')->default(true);
        $table->boolean('include_dropoff_to_return')->default(true);
        $table->string('movement_rate_method')->default('normal_rate');
        $table->decimal('outbound_rate', 12, 2)->nullable();
        $table->decimal('return_rate', 12, 2)->nullable();
        $table->decimal('maximum_outbound_km', 10, 2)->nullable();
        $table->decimal('maximum_return_km', 10, 2)->nullable();
        $table->timestamp('effective_from')->nullable();
        $table->timestamp('effective_until')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('corporate_service_distance_policies', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('corporate_id');
        $table->uuid('service_type_id');
        $table->uuid('policy_id')->nullable();
        $table->string('application_mode')->default('inherit');
        $table->json('origin_location_override')->nullable();
        $table->json('return_location_override')->nullable();
        $table->boolean('include_origin_to_pickup')->nullable();
        $table->boolean('include_dropoff_to_return')->nullable();
        $table->string('movement_rate_method')->nullable();
        $table->decimal('outbound_rate', 12, 2)->nullable();
        $table->decimal('return_rate', 12, 2)->nullable();
        $table->decimal('maximum_outbound_km', 10, 2)->nullable();
        $table->decimal('maximum_return_km', 10, 2)->nullable();
        $table->timestamp('effective_from')->nullable();
        $table->timestamp('effective_until')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('drivers', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id')->nullable();
        $table->string('code')->nullable();
        $table->string('license_no')->nullable();
        $table->boolean('is_online')->default(false);
        $table->timestamp('last_active_at')->nullable();
        $table->decimal('current_latitude', 10, 8)->nullable();
        $table->decimal('current_longitude', 11, 8)->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('driver_assignments', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('driver_id')->nullable();
        $table->uuid('booking_id')->nullable();
        $table->uuid('booking_item_id')->nullable();
        $table->uuid('confirmed_by')->nullable();
        $table->string('assigned_by_name_snapshot')->nullable();
        $table->string('confirmed_by_name_snapshot')->nullable();
        $table->string('driver_name_snapshot')->nullable();
        $table->timestamp('confirmed_at')->nullable();
        $table->timestamp('assigned_from')->nullable();
        $table->timestamp('assigned_to')->nullable();
        $table->string('status')->default('active');
        $table->string('trip_phase')->default('active');
        $table->timestamp('pickup_arrived_at')->nullable();
        $table->decimal('pickup_arrival_latitude', 10, 8)->nullable();
        $table->decimal('pickup_arrival_longitude', 11, 8)->nullable();
        $table->timestamp('trip_started_at')->nullable();
        $table->timestamp('trip_completed_at')->nullable();
        $table->timestamp('actual_start')->nullable();
        $table->timestamp('actual_end')->nullable();
        $table->decimal('final_latitude', 10, 8)->nullable();
        $table->decimal('final_longitude', 11, 8)->nullable();
        $table->decimal('total_distance_km', 10, 2)->nullable();
        $table->unsignedInteger('total_waiting_time_seconds')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('driver_assignment_stops', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('assignment_id');
        $table->uuid('booking_id')->nullable();
        $table->uuid('booking_item_id')->nullable();
        $table->string('booking_stop_id')->nullable();
        $table->string('stop_type');
        $table->unsignedInteger('route_order');
        $table->unsignedInteger('type_sequence')->nullable();
        $table->string('status')->default('pending');
        $table->json('location')->nullable();
        $table->string('label')->nullable();
        $table->text('address')->nullable();
        $table->decimal('latitude', 10, 8)->nullable();
        $table->decimal('longitude', 11, 8)->nullable();
        $table->timestamp('arrived_at')->nullable();
        $table->decimal('arrived_latitude', 10, 8)->nullable();
        $table->decimal('arrived_longitude', 11, 8)->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->decimal('completed_latitude', 10, 8)->nullable();
        $table->decimal('completed_longitude', 11, 8)->nullable();
        $table->string('completed_action')->nullable();
        $table->text('skip_reason')->nullable();
        $table->text('notes')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('route_points', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('session_id')->nullable();
        $table->uuid('assignment_id')->nullable();
        $table->decimal('latitude', 10, 8);
        $table->decimal('longitude', 11, 8);
        $table->decimal('altitude', 10, 2)->nullable();
        $table->decimal('speed', 10, 2)->nullable();
        $table->decimal('heading', 10, 2)->nullable();
        $table->decimal('accuracy', 10, 2)->nullable();
        $table->timestamp('recorded_at');
        $table->timestamp('created_at')->nullable();
    });

    Schema::create('driver_sessions', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('driver_id');
        $table->uuid('assignment_id')->nullable();
        $table->string('status')->default('active');
        $table->timestamp('start_time')->nullable();
        $table->timestamp('last_heartbeat_at')->nullable();
        $table->timestamp('logged_out_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $waiting = Mockery::mock(WaitingTimeService::class);
    $waiting->shouldReceive('getTotalWaitingTime')->byDefault()->andReturn([
        'total_waiting_time_seconds' => 0,
        'waiting_period_count' => 0,
    ]);
    $waiting->shouldReceive('closeOpenWaitingRecords')->byDefault();
    $this->bookingLifecycle = Mockery::mock(BookingLifecycleService::class);
    $this->bookingLifecycle->shouldReceive('processReturn')->byDefault()->andReturnUsing(
        fn (string $bookingId) => BookingDispatch::where('booking_id', $bookingId)->firstOrFail()
    );
    $this->bookingLifecycle->shouldReceive('completeBooking')->byDefault()->andReturnUsing(
        fn (string $bookingId) => Booking::findOrFail($bookingId)
    );
    $this->tripService = new TripTrackingService($waiting, $this->bookingLifecycle);
    $this->assignmentService = new MobileAssignmentService(
        Mockery::mock(NotificationTriggerService::class),
        $this->tripService,
    );
});

it('persists single and buffered tracking without changing contractual pricing snapshots', function () {
    $contractualSnapshot = [
        'base_pricing' => [
            'distance_policy' => [
                'policy_id' => 'policy-tracking-boundary',
                'coordinate_source' => 'corporate_distance_policy',
                'defined_origin' => ['latitude' => 6.8, 'longitude' => 79.8],
                'defined_return' => ['latitude' => 6.81, 'longitude' => 79.81],
            ],
            'distance_details' => ['total_billable_distance' => 24],
        ],
    ];
    $booking = Booking::create([
        'pricing_snapshot' => $contractualSnapshot,
        'total_estimated' => 5000,
    ]);
    $item = BookingItem::create([
        'booking_id' => $booking->id,
        'pricing_breakdown' => $contractualSnapshot,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);
    $driver = Driver::create(['code' => 'TRACKING-BOUNDARY']);
    $assignment = DriverAssignment::create([
        'driver_id' => $driver->id,
        'booking_id' => $booking->id,
        'booking_item_id' => $item->id,
        'trip_phase' => TripPhase::IN_PROGRESS,
        'status' => 'active',
    ]);
    $session = DriverSession::create([
        'driver_id' => $driver->id,
        'assignment_id' => $assignment->id,
        'status' => 'active',
        'start_time' => now()->subMinutes(5),
    ]);
    $locations = new LocationService();

    $single = $locations->updateLocation($driver, [
        'latitude' => 6.91,
        'longitude' => 79.81,
        'recorded_at' => now()->subMinutes(2)->toIso8601String(),
    ]);
    $bulk = $locations->syncBufferedLocations($driver->fresh(), [[
        'latitude' => 6.92,
        'longitude' => 79.82,
        'recorded_at' => now()->subMinute()->toIso8601String(),
    ], [
        'latitude' => 6.93,
        'longitude' => 79.83,
        'recorded_at' => now()->toIso8601String(),
    ]]);

    expect($single->session_id)->toBe($session->id)
        ->and($single->assignment_id)->toBe($assignment->id)
        ->and($bulk['saved_count'])->toBe(2)
        ->and(RoutePoint::where('session_id', $session->id)->count())->toBe(3)
        ->and(RoutePoint::where('session_id', $session->id)->where('assignment_id', $assignment->id)->count())->toBe(3)
        ->and($booking->fresh()->pricing_snapshot)->toBe($contractualSnapshot)
        ->and($item->fresh()->pricing_breakdown)->toBe($contractualSnapshot)
        ->and((float) $booking->fresh()->total_estimated)->toBe(5000.0)
        ->and((float) $item->fresh()->total_price)->toBe(5000.0);
});

it('rejects stale and cross-context recovery without rewriting raw claims', function () {
    $driver = Driver::create(['code' => 'RECOVERY-CONTEXT']);
    $assignment = DriverAssignment::create([
        'driver_id' => $driver->id,
        'trip_phase' => TripPhase::IN_PROGRESS,
        'status' => 'active',
        'trip_started_at' => now()->subMinutes(10),
    ]);
    $session = DriverSession::create([
        'driver_id' => $driver->id,
        'assignment_id' => $assignment->id,
        'status' => 'active',
        'start_time' => now()->subMinutes(15),
    ]);

    $result = (new LocationService())->syncBufferedLocations($driver, [[
        'latitude' => 7.20,
        'longitude' => 80.20,
        'recorded_at' => now()->subDay()->toIso8601String(),
        'session_id' => $session->id,
        'assignment_id' => $assignment->id,
    ], [
        'latitude' => 7.21,
        'longitude' => 80.21,
        'recorded_at' => now()->subMinute()->toIso8601String(),
        'session_id' => '00000000-0000-0000-0000-000000000001',
        'assignment_id' => $assignment->id,
    ], [
        'latitude' => 7.22,
        'longitude' => 80.22,
        'recorded_at' => now()->subMinute()->toIso8601String(),
        'session_id' => $session->id,
        'assignment_id' => $assignment->id,
    ]]);

    expect($result['accepted_count'])->toBe(1)
        ->and($result['quarantined_count'])->toBe(2)
        ->and(collect($result['outcomes'])->pluck('reason')->all())->toBe([
            'outside_session_window', 'session_context_mismatch', 'accepted',
        ])
        ->and(RoutePoint::where('session_id', $session->id)->count())->toBe(1);
});

it('accepts same-trip offline recovery idempotently', function () {
    $driver = Driver::create(['code' => 'RECOVERY-IDEMPOTENT']);
    $assignment = DriverAssignment::create([
        'driver_id' => $driver->id,
        'trip_phase' => TripPhase::IN_PROGRESS,
        'status' => 'active',
        'trip_started_at' => now()->subMinutes(10),
    ]);
    $session = DriverSession::create([
        'driver_id' => $driver->id,
        'assignment_id' => $assignment->id,
        'status' => 'active',
        'start_time' => now()->subMinutes(15),
    ]);
    $point = [
        'latitude' => 7.20,
        'longitude' => 80.20,
        'recorded_at' => now()->subMinute()->startOfSecond()->toIso8601String(),
        'session_id' => $session->id,
        'trip_id' => $assignment->id,
    ];
    $service = new LocationService();

    $first = $service->syncBufferedLocations($driver, [$point]);
    $second = $service->syncBufferedLocations($driver->fresh(), [$point]);

    expect($first['accepted_count'])->toBe(1)
        ->and($second['accepted_count'])->toBe(0)
        ->and($second['duplicate_count'])->toBe(1)
        ->and($second['outcomes'][0]['reason'])->toBe('duplicate')
        ->and(RoutePoint::where('session_id', $session->id)->count())->toBe(1);
});

it('projects only chronologically ordered booked stops and excludes pricing-only locations', function () {
    $pricingOnlyOrigin = ['address' => 'Pricing Depot', 'latitude' => 6.80, 'longitude' => 79.80];
    $pricingOnlyReturn = ['address' => 'Pricing Return', 'latitude' => 6.81, 'longitude' => 79.81];
    $booking = Booking::create(['status' => 'confirmed']);
    $item = BookingItem::create([
        'booking_id' => $booking->id,
        'pickup_location' => ['address' => 'Passenger Pickup', 'latitude' => 6.90, 'longitude' => 79.90],
        'pickup_latitude' => 6.90,
        'pickup_longitude' => 79.90,
        'dropoff_location' => ['address' => 'Passenger Drop-off', 'latitude' => 6.95, 'longitude' => 79.95],
        'dropoff_latitude' => 6.95,
        'dropoff_longitude' => 79.95,
        'metadata' => [
            'multi_route_stop_order' => [[
                'type' => 'pickup',
                'stop_id' => 'booked-pickup-2',
                'address' => 'Passenger Pickup 2',
                'latitude' => 6.91,
                'longitude' => 79.91,
            ], [
                'type' => 'dropoff',
                'stop_id' => 'booked-dropoff-1',
                'address' => 'Passenger Drop-off 1',
                'latitude' => 6.94,
                'longitude' => 79.94,
            ]],
        ],
        'pricing_breakdown' => [
            'base_pricing' => [
                'distance_policy' => [
                    'coordinate_source' => 'corporate_distance_policy',
                    'defined_origin' => $pricingOnlyOrigin,
                    'defined_return' => $pricingOnlyReturn,
                ],
            ],
        ],
    ]);
    $assignment = DriverAssignment::create([
        'booking_id' => $booking->id,
        'booking_item_id' => $item->id,
        'trip_phase' => TripPhase::ACCEPTED,
        'status' => 'active',
    ]);

    $stops = $this->tripService->ensureAssignmentStops($assignment);
    $mobileStops = $this->tripService->mapStopsForMobile($stops->shuffle());

    expect($stops->pluck('route_order')->all())->toBe([1, 2, 3, 4])
        ->and(array_column($mobileStops, 'route_order'))->toBe([1, 2, 3, 4])
        ->and(array_column($mobileStops, 'type'))->toBe(['pickup', 'pickup', 'dropoff', 'dropoff'])
        ->and(array_column($mobileStops, 'address'))->toBe([
            'Passenger Pickup',
            'Passenger Pickup 2',
            'Passenger Drop-off 1',
            'Passenger Drop-off',
        ])
        ->and(collect($mobileStops)->pluck('address'))->not->toContain('Pricing Depot')
        ->and(collect($mobileStops)->pluck('address'))->not->toContain('Pricing Return')
        ->and(collect($mobileStops)->pluck('latitude'))->not->toContain(6.80)
        ->and(collect($mobileStops)->pluck('latitude'))->not->toContain(6.81)
        ->and($this->tripService->getAssignmentAllowedActions($assignment, $stops))->toBe(['arrived', 'start']);
});

it('runs configured contractual pricing through approval dispatch driver completion and invoice projection', function () {
    $policy = CorporateDistancePricingPolicy::create([
        'corporate_id' => 'company-e2e',
        'name' => 'Airport movement contract',
        'is_default' => true,
        'default_service_mode' => 'enabled',
        'origin_address' => 'Defined Origin',
        'origin_latitude' => 6.80,
        'origin_longitude' => 79.80,
        'return_address' => 'Defined Return',
        'return_latitude' => 6.81,
        'return_longitude' => 79.81,
        'include_origin_to_pickup' => true,
        'include_dropoff_to_return' => true,
        'movement_rate_method' => 'normal_rate',
        'is_active' => true,
    ]);
    $maps = Mockery::mock(GoogleMapsService::class);
    $maps->shouldReceive('distanceAndDuration')->times(3)->andReturnValues([
        ['distance_km' => 10, 'duration_seconds' => 600],
        ['distance_km' => 2, 'duration_seconds' => 180],
        ['distance_km' => 12, 'duration_seconds' => 720],
    ]);
    $contractual = (new CorporateContractualDistanceCalculator(
        app(CorporateDistancePolicyResolver::class),
        $maps,
    ))->calculate('company-e2e', 'service-airport', [[
        'address' => 'Passenger Pickup', 'latitude' => 6.90, 'longitude' => 79.90,
    ], [
        'address' => 'Passenger Drop-off', 'latitude' => 6.95, 'longitude' => 79.95,
    ]]);

    $flow = new BookingFlowService(
        Mockery::mock(CurrencyService::class),
        Mockery::mock(PricingVariableService::class),
        Mockery::mock(AssignmentService::class),
        Mockery::mock(AvailabilityEnforcementService::class),
        Mockery::mock(MailDispatchService::class),
        Mockery::mock(\App\Services\Sms\SmsAutomationService::class),
    );
    $transform = new ReflectionMethod($flow, 'transformCalculationResult');
    $confirmedSnapshot = ['base_pricing' => $transform->invoke($flow, [
        'total_amount' => 5000,
        'total_amount_without_customizations' => 5000,
        'km_calculations' => ['journey_distance' => 2],
    ], [
        'corporate_account_id' => 'company-e2e',
        'contractual_distance_calculation' => $contractual,
    ], 'full_calculation')];

    $booking = Booking::create([
        'status' => 'pending_approval',
        'corporate_account_id' => 'company-e2e',
        'pricing_snapshot' => $confirmedSnapshot,
        'total_estimated' => 5000,
        'currency' => 'LKR',
    ]);
    $item = BookingItem::create([
        'booking_id' => $booking->id,
        'metadata' => ['trip_mode' => 'open_package'],
        'pricing_breakdown' => $confirmedSnapshot,
        'pickup_location' => ['address' => 'Passenger Pickup', 'latitude' => 6.90, 'longitude' => 79.90],
        'dropoff_location' => ['address' => 'Passenger Drop-off', 'latitude' => 6.95, 'longitude' => 79.95],
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);
    DB::table('booking_approvals')->insert([
        'id' => 'approval-e2e', 'booking_id' => $booking->id, 'status' => 'approved', 'approved_at' => now(),
    ]);
    $booking->update(['status' => 'confirmed']);
    DB::table('booking_dispatches')->insert([
        'id' => 'dispatch-e2e', 'booking_id' => $booking->id, 'dispatch_status' => 'dispatched', 'dispatched_at' => now(),
    ]);

    $driver = Driver::create(['code' => 'E2E-DRIVER']);
    $assignment = DriverAssignment::create([
        'driver_id' => $driver->id,
        'booking_id' => $booking->id,
        'booking_item_id' => $item->id,
        'trip_phase' => TripPhase::ACTIVE,
        'status' => 'active',
    ]);
    $this->assignmentService->acceptAssignment($driver, $assignment);
    $this->tripService->confirmPickupArrival($assignment->fresh(), ['latitude' => 7.20, 'longitude' => 80.20]);
    $this->tripService->startTrip($assignment->fresh());
    $assignment->update(['trip_started_at' => now()->subMinutes(2)]);
    RoutePoint::create([
        'assignment_id' => $assignment->id, 'latitude' => 7.20, 'longitude' => 80.20, 'recorded_at' => now()->subMinute(),
    ]);
    RoutePoint::create([
        'assignment_id' => $assignment->id, 'latitude' => 7.2001, 'longitude' => 80.2001, 'recorded_at' => now(),
    ]);
    $this->tripService->endTrip($assignment->fresh(), ['latitude' => 7.50, 'longitude' => 80.50]);

    $policy->update(['origin_latitude' => 8.00, 'return_latitude' => 8.10]);
    $booking->refresh();
    $item->refresh();
    $item->setRelation('serviceType', null);
    $booking->setRelation('bookingItems', collect([$item]));
    $invoice = new InvoiceService(new ContractualDistanceSnapshotProjector());
    $invoiceProjection = new ReflectionMethod($invoice, 'contractualDistanceBreakdowns');
    $breakdowns = $invoiceProjection->invoke($invoice, $booking);

    expect((float) data_get($booking->pricing_snapshot, 'base_pricing.distance_details.total_billable_distance'))->toBe(24.0)
        ->and((float) data_get($item->pricing_breakdown, 'base_pricing.distance_policy.defined_origin.latitude'))->toBe(6.8)
        ->and(DB::table('booking_approvals')->where('booking_id', $booking->id)->value('status'))->toBe('approved')
        ->and(DB::table('booking_dispatches')->where('booking_id', $booking->id)->value('dispatched_at'))->not->toBeNull()
        ->and($assignment->fresh()->trip_phase)->toBe(TripPhase::COMPLETED)
        ->and(RoutePoint::where('assignment_id', $assignment->id)->count())->toBe(2)
        ->and((float) $assignment->fresh()->final_latitude)->toBe(7.5)
        ->and((float) $assignment->fresh()->total_distance_km)->toBeGreaterThan(0)
        ->and((float) $assignment->fresh()->total_distance_km)->not->toBe(24.0)
        ->and($breakdowns[0]['defined_origin']['latitude'])->toBe(6.8)
        ->and($breakdowns[0]['defined_return']['latitude'])->toBe(6.81)
        ->and($breakdowns[0]['total_billable_distance'])->toBe(24.0);
});

it('keeps the supported assignment payload unchanged for a non-policy booking', function () {
    $booking = Booking::create([
        'status' => 'confirmed',
        'booking_number' => 'NORMAL-1001',
        'total_estimated' => 3200,
        'currency' => 'LKR',
    ]);
    $booking->setRelation('customer', null);
    $item = BookingItem::create([
        'booking_id' => $booking->id,
        'metadata' => ['trip_mode' => 'open_package'],
        'unit_price' => 3200,
        'total_price' => 3200,
    ]);
    foreach (['serviceType', 'vehicle', 'vehicleGroup'] as $relation) {
        $item->setRelation($relation, null);
    }
    $driver = Driver::create(['code' => 'NORMAL-DRIVER']);
    $assignment = DriverAssignment::create([
        'driver_id' => $driver->id,
        'booking_id' => $booking->id,
        'booking_item_id' => $item->id,
        'trip_phase' => TripPhase::ACTIVE,
        'status' => 'active',
    ]);
    $assignment->setRelation('booking', $booking);
    $assignment->setRelation('bookingItem', $item);
    $assignment->setRelation('stops', collect());

    $payload = $this->assignmentService->buildAssignmentPayload($assignment);

    expect($payload)->toHaveKeys([
        'id',
        'driver_id',
        'booking_id',
        'booking_item_id',
        'status',
        'trip_phase',
        'payment_type',
        'fare_amount',
        'total_amount',
        'currency',
        'booking_number',
        'trip_mode',
        'destination_known',
        'route_stops',
        'allowed_actions',
    ])->and($payload['booking_number'])->toBe('NORMAL-1001')
        ->and($payload['total_amount'])->toBe(3200.0)
        ->and($payload['trip_mode'])->toBe('open_package')
        ->and($payload['route_stops'])->toBe([])
        ->and($payload['allowed_actions'])->toBe(['accept', 'decline'])
        ->and($payload)->not->toHaveKeys([
            'contractual_distance_snapshot',
            'defined_origin',
            'defined_return',
            'distance_policy',
        ]);
});

it('reconciles a stale booking item pointer for a single-item driver assignment', function () {
    $booking = Booking::create(['status' => 'confirmed']);
    $canonicalItem = BookingItem::create(['booking_id' => $booking->id]);
    $otherBooking = Booking::create(['status' => 'confirmed']);
    $staleItem = BookingItem::create(['booking_id' => $otherBooking->id]);
    $driver = Driver::create(['code' => 'LEGACY-ITEM-DRIVER']);
    $assignment = DriverAssignment::create([
        'driver_id' => $driver->id,
        'booking_id' => $booking->id,
        'booking_item_id' => $staleItem->id,
        'trip_phase' => TripPhase::IN_PROGRESS,
        'status' => 'active',
    ]);
    $booking->setRelation('bookingItems', collect([$canonicalItem]));

    $resolveItem = new ReflectionMethod($this->tripService, 'resolveCanonicalAssignmentBookingItemId');
    $resolvedItemId = $resolveItem->invoke($this->tripService, $assignment, $booking);

    expect($resolvedItemId)->toBe((string) $canonicalItem->id)
        ->and((string) $assignment->fresh()->booking_item_id)->toBe((string) $canonicalItem->id);
});

it('does not guess a replacement item for a multi-item driver assignment', function () {
    $booking = Booking::create(['status' => 'confirmed']);
    $firstItem = BookingItem::create(['booking_id' => $booking->id]);
    $secondItem = BookingItem::create(['booking_id' => $booking->id]);
    $otherBooking = Booking::create(['status' => 'confirmed']);
    $staleItem = BookingItem::create(['booking_id' => $otherBooking->id]);
    $driver = Driver::create(['code' => 'AMBIGUOUS-ITEM-DRIVER']);
    $assignment = DriverAssignment::create([
        'driver_id' => $driver->id,
        'booking_id' => $booking->id,
        'booking_item_id' => $staleItem->id,
        'trip_phase' => TripPhase::IN_PROGRESS,
        'status' => 'active',
    ]);
    $booking->setRelation('bookingItems', collect([$firstItem, $secondItem]));
    $resolveItem = new ReflectionMethod($this->tripService, 'resolveCanonicalAssignmentBookingItemId');

    expect(fn () => $resolveItem->invoke($this->tripService, $assignment, $booking))
        ->toThrow(InvalidArgumentException::class, 'Selected booking item does not belong to this booking')
        ->and((string) $assignment->fresh()->booking_item_id)->toBe((string) $staleItem->id);
});

it('rejects accept and decline mutations for an assignment owned by another driver', function () {
    $owner = Driver::create(['code' => 'OWNER']);
    $other = Driver::create(['code' => 'OTHER']);
    $assignment = DriverAssignment::create([
        'driver_id' => $owner->id,
        'trip_phase' => TripPhase::ACTIVE,
        'status' => 'active',
    ]);

    expect(fn () => $this->assignmentService->acceptAssignment($other, $assignment))
        ->toThrow(InvalidArgumentException::class, 'ASSIGNMENT_NOT_FOUND')
        ->and(fn () => $this->assignmentService->declineAssignment($other, $assignment, 'Not mine'))
        ->toThrow(InvalidArgumentException::class, 'ASSIGNMENT_NOT_FOUND');

    expect($assignment->fresh()->status)->toBe('active')
        ->and($assignment->fresh()->trip_phase)->toBe(TripPhase::ACTIVE);
});

it('persists pickup arrival coordinates once and tolerates a mobile retry', function () {
    $assignment = DriverAssignment::create(['trip_phase' => TripPhase::ACCEPTED, 'status' => 'active']);

    $this->tripService->confirmPickupArrival($assignment, ['latitude' => 6.91, 'longitude' => 79.81]);
    $firstArrival = $assignment->fresh();
    $this->tripService->confirmPickupArrival($firstArrival, ['latitude' => 7.5, 'longitude' => 80.5]);
    $retried = $assignment->fresh();

    expect($retried->trip_phase)->toBe(TripPhase::PICKUP_ARRIVED)
        ->and((float) $retried->pickup_arrival_latitude)->toBe(6.91)
        ->and((float) $retried->pickup_arrival_longitude)->toBe(79.81)
        ->and($retried->pickup_arrived_at?->equalTo($firstArrival->pickup_arrived_at))->toBeTrue();
});

it('persists trip start once and returns the canonical next action', function () {
    $assignment = DriverAssignment::create(['trip_phase' => TripPhase::PICKUP_ARRIVED, 'status' => 'active']);

    $this->tripService->startTrip($assignment);
    $started = $assignment->fresh();
    $this->tripService->startTrip($started);
    $retried = $assignment->fresh();

    expect($retried->trip_phase)->toBe(TripPhase::IN_PROGRESS)
        ->and($retried->trip_started_at?->equalTo($started->trip_started_at))->toBeTrue()
        ->and($this->tripService->getAssignmentAllowedActions($retried, collect()))->toBe(['complete']);
});

it('enforces stop order and preserves submitted arrival coordinates', function () {
    $assignment = DriverAssignment::create(['trip_phase' => TripPhase::IN_PROGRESS, 'status' => 'active']);
    $first = DriverAssignmentStop::create([
        'assignment_id' => $assignment->id, 'stop_type' => 'pickup', 'route_order' => 1, 'status' => 'pending',
    ]);
    $second = DriverAssignmentStop::create([
        'assignment_id' => $assignment->id, 'stop_type' => 'dropoff', 'route_order' => 2, 'status' => 'pending',
    ]);

    expect(fn () => $this->tripService->markStopArrived($assignment, $second, [
        'latitude' => 7.2, 'longitude' => 80.2,
    ]))->toThrow(InvalidArgumentException::class, 'STOP_OUT_OF_SEQUENCE');

    $status = $this->tripService->markStopArrived($assignment, $first, [
        'latitude' => 6.92, 'longitude' => 79.82,
    ]);

    expect($first->fresh()->status)->toBe('arrived')
        ->and((float) $first->fresh()->arrived_latitude)->toBe(6.92)
        ->and($status['allowed_actions'])->toBe(['stop_action']);
});

it('persists actual completion and route distance once without a pricing owner', function () {
    $assignment = DriverAssignment::create([
        'trip_phase' => TripPhase::IN_PROGRESS,
        'status' => 'active',
        'trip_started_at' => now()->subMinutes(2),
    ]);
    RoutePoint::create([
        'assignment_id' => $assignment->id, 'latitude' => 6.90, 'longitude' => 79.80, 'recorded_at' => now()->subMinute(),
    ]);
    RoutePoint::create([
        'assignment_id' => $assignment->id, 'latitude' => 6.91, 'longitude' => 79.81, 'recorded_at' => now(),
    ]);

    $summary = $this->tripService->endTrip($assignment, ['latitude' => 6.95, 'longitude' => 79.85]);
    $completed = $assignment->fresh();
    $retry = $this->tripService->endTrip($completed, []);

    expect($completed->trip_phase)->toBe(TripPhase::COMPLETED)
        ->and((float) $completed->final_latitude)->toBe(6.95)
        ->and((float) $completed->final_longitude)->toBe(79.85)
        ->and((float) $completed->total_distance_km)->toBeGreaterThan(0)
        ->and($retry['total_distance_km'])->toBe($summary['total_distance_km']);
});

it('reconciles a stale mobile assignment when the parent booking is already completed', function () {
    $booking = Booking::create([
        'status' => 'completed',
        'completed_at' => now()->subMinute(),
    ]);
    $item = BookingItem::create(['booking_id' => $booking->id]);
    $driver = Driver::create(['code' => 'STALE-COMPLETED-HIRE']);
    $assignment = DriverAssignment::create([
        'driver_id' => $driver->id,
        'booking_id' => $booking->id,
        'booking_item_id' => $item->id,
        'trip_phase' => TripPhase::IN_PROGRESS,
        'status' => 'active',
        'trip_started_at' => now()->subMinutes(10),
    ]);

    $summary = $this->tripService->endTrip($assignment, [
        'latitude' => 6.95,
        'longitude' => 79.85,
    ]);

    $completed = $assignment->fresh();
    expect($completed->trip_phase)->toBe(TripPhase::COMPLETED)
        ->and($completed->status)->toBe('completed')
        ->and($completed->trip_completed_at)->not->toBeNull()
        ->and($summary['hire_completed'])->toBeTrue();
    $this->bookingLifecycle->shouldNotHaveReceived('completeBooking');
});

it('does not expose terminal parent bookings as upcoming or current assignments', function () {
    $driver = Driver::create(['code' => 'TERMINAL-FILTER']);
    $completedBooking = Booking::create(['status' => 'completed', 'completed_at' => now()]);
    $activeBooking = Booking::create(['status' => 'confirmed']);

    $staleAssignment = DriverAssignment::create([
        'driver_id' => $driver->id,
        'booking_id' => $completedBooking->id,
        'trip_phase' => TripPhase::IN_PROGRESS,
        'status' => 'active',
        'assigned_from' => now()->subHour(),
        'assigned_to' => now()->addHour(),
    ]);
    $staleSession = DriverSession::create([
        'driver_id' => $driver->id,
        'assignment_id' => $staleAssignment->id,
        'status' => 'active',
        'start_time' => now()->subHour(),
    ]);
    $activeAssignment = DriverAssignment::create([
        'driver_id' => $driver->id,
        'booking_id' => $activeBooking->id,
        'trip_phase' => TripPhase::ACCEPTED,
        'status' => 'active',
        'assigned_from' => now()->subHour(),
        'assigned_to' => now()->addHour(),
    ]);

    $upcoming = $this->assignmentService->getDriverAssignments($driver, ['status' => 'upcoming']);
    $defaultInbox = $this->assignmentService->getDriverAssignments($driver);

    expect($upcoming->pluck('id')->all())->toBe([$activeAssignment->id])
        ->and($defaultInbox->pluck('id')->all())->toBe([$activeAssignment->id])
        ->and($this->assignmentService->getCurrentAssignment($driver)?->id)->toBe($activeAssignment->id)
        ->and($staleAssignment->fresh()->trip_phase)->toBe(TripPhase::COMPLETED)
        ->and($staleAssignment->fresh()->status)->toBe('completed')
        ->and($staleSession->fresh()->assignment_id)->toBeNull();
});

it('uses lifecycle final pricing as the single open package charge owner', function () {
    $booking = Booking::create([
        'status' => 'confirmed',
        'total_estimated' => 100,
        'currency' => 'LKR',
    ]);
    $item = BookingItem::create([
        'booking_id' => $booking->id,
        'metadata' => [
            'trip_mode' => 'open_package',
            'included_km' => 0,
            'extra_km_rate' => 100,
            'included_minutes' => 0,
            'extra_hour_rate' => 100,
        ],
        'unit_price' => 100,
        'total_price' => 100,
    ]);
    DB::table('booking_dispatches')->insert([
        'id' => 'dispatch-single-price-owner',
        'booking_id' => $booking->id,
        'dispatch_status' => 'dispatched',
        'dispatched_at' => now()->subHours(2),
    ]);
    $dispatch = \App\Models\Booking\BookingDispatch::findOrFail('dispatch-single-price-owner');
    $driver = Driver::create(['code' => 'OPEN-PACKAGE-OWNER']);
    $assignment = DriverAssignment::create([
        'driver_id' => $driver->id,
        'booking_id' => $booking->id,
        'booking_item_id' => $item->id,
        'trip_phase' => TripPhase::IN_PROGRESS,
        'trip_started_at' => now()->subHours(2),
        'status' => 'active',
    ]);
    RoutePoint::create([
        'assignment_id' => $assignment->id,
        'latitude' => 6.90,
        'longitude' => 79.80,
        'recorded_at' => now()->subMinute(),
    ]);
    RoutePoint::create([
        'assignment_id' => $assignment->id,
        'latitude' => 6.95,
        'longitude' => 79.85,
        'recorded_at' => now(),
    ]);

    $this->bookingLifecycle
        ->shouldReceive('processReturn')
        ->once()
        ->withArgs(fn (string $bookingId, array $payload) => $bookingId === $booking->id
            && $payload['booking_item_id'] === $item->id
            && $payload['completed_by_driver'] === true)
        ->andReturnUsing(function () use ($item, $booking, $dispatch) {
            $audit = ['status' => 'calculated', 'final_base' => 150.0];
            $item->update([
                'unit_price' => 150,
                'total_price' => 150,
                'pricing_breakdown' => ['final_pricing' => ['audit' => $audit]],
            ]);
            $booking->update(['total_actual' => 150]);

            return $dispatch;
        });

    $summary = $this->tripService->endTrip($assignment, [
        'latitude' => 6.95,
        'longitude' => 79.85,
    ]);

    expect((float) $item->fresh()->total_price)->toBe(150.0)
        ->and(data_get($item->fresh()->pricing_breakdown, 'open_package_final'))->toBeNull()
        ->and((float) $summary['package_charges']['final_base'])->toBe(150.0);
});

it('preserves the enabled contractual snapshot through the full operational lifecycle', function () {
    $contractualSnapshot = [
        'base_pricing' => [
            'distance_policy' => [
                'policy_id' => 'policy-a',
                'coordinate_source' => 'corporate_distance_policy',
                'defined_origin' => ['address' => 'Contract Base', 'latitude' => 6.8, 'longitude' => 79.8],
                'defined_return' => ['address' => 'Return Base', 'latitude' => 6.81, 'longitude' => 79.81],
            ],
            'distance_details' => [
                'origin_to_pickup_distance' => 10,
                'journey_distance' => 2,
                'dropoff_to_return_distance' => 12,
                'total_billable_distance' => 24,
            ],
        ],
    ];

    $booking = Booking::create([
        'status' => 'confirmed',
        'pricing_snapshot' => $contractualSnapshot,
        'total_estimated' => 5000,
        'currency' => 'LKR',
        'payment_collection_method' => 'monthly_invoice',
        'payment_collection_status' => 'pending',
    ]);
    $item = BookingItem::create([
        'booking_id' => $booking->id,
        'metadata' => ['trip_mode' => 'open_package'],
        'pricing_breakdown' => $contractualSnapshot,
        'dropoff_location' => ['address' => 'Booked Drop-off', 'latitude' => 6.93, 'longitude' => 79.83],
        'dropoff_latitude' => 6.93,
        'dropoff_longitude' => 79.83,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);
    $dispatchedAt = now()->subMinutes(10);
    DB::table('booking_dispatches')->insert([
        'id' => 'dispatch-policy-a',
        'booking_id' => $booking->id,
        'dispatch_status' => 'dispatched',
        'dispatched_at' => $dispatchedAt,
    ]);
    $driver = Driver::create(['code' => 'POLICY-DRIVER']);
    $assignment = DriverAssignment::create([
        'driver_id' => $driver->id,
        'booking_id' => $booking->id,
        'booking_item_id' => $item->id,
        'trip_phase' => TripPhase::ACTIVE,
        'status' => 'active',
    ]);

    $accepted = $this->assignmentService->acceptAssignment($driver, $assignment);
    $this->tripService->confirmPickupArrival($accepted, ['latitude' => 6.91, 'longitude' => 79.81]);
    $this->tripService->startTrip($accepted->fresh());
    RoutePoint::create([
        'assignment_id' => $assignment->id, 'latitude' => 6.91, 'longitude' => 79.81, 'recorded_at' => now()->subMinute(),
    ]);
    RoutePoint::create([
        'assignment_id' => $assignment->id, 'latitude' => 6.94, 'longitude' => 79.84, 'recorded_at' => now(),
    ]);
    $this->tripService->endTrip($accepted->fresh(), ['latitude' => 6.95, 'longitude' => 79.85]);

    $completed = $assignment->fresh();
    $booking->refresh();
    $item->refresh();

    expect($completed->confirmed_at)->not->toBeNull()
        ->and($completed->pickup_arrived_at)->not->toBeNull()
        ->and((float) $completed->pickup_arrival_latitude)->toBe(6.91)
        ->and($completed->trip_started_at)->not->toBeNull()
        ->and($completed->trip_completed_at)->not->toBeNull()
        ->and((float) $completed->final_latitude)->toBe(6.95)
        ->and(RoutePoint::where('assignment_id', $assignment->id)->count())->toBe(2)
        ->and(DB::table('booking_dispatches')->where('booking_id', $booking->id)->value('dispatched_at'))->not->toBeNull()
        ->and($booking->pricing_snapshot)->toBe($contractualSnapshot)
        ->and($item->pricing_breakdown)->toBe($contractualSnapshot)
        ->and($item->dropoff_location['address'])->toBe('Booked Drop-off')
        ->and(data_get($booking->distance_metrics, 'source'))->toBe('driver_route_points')
        ->and(data_get($booking->distance_metrics, "items.{$item->id}.pricing_effect"))
        ->toBe('none_contractual_snapshot');
});
