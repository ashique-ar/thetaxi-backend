<?php

use App\Enums\DispatchStatus;
use App\Enums\VehicleAvailabilityStatus;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingDispatch;
use App\Models\Booking\BookingItem;
use App\Models\Vehicle\Vehicle;
use App\Services\AgentCommissionService;
use App\Services\AssignmentService;
use App\Services\AvailabilityEnforcementService;
use App\Services\BookingFlowService;
use App\Services\BookingLifecycleService;
use App\Services\CurrencyService;
use App\Services\CustomerMobileActivityService;
use App\Services\InvoiceService;
use App\Services\LoyaltyService;
use App\Services\Pricing\FinalPricingTelemetryResolver;
use App\Services\WebsiteSettingsService;
use App\Services\Driver\NotificationTriggerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    activity()->disableLogging();

    foreach ([
        'audit_logs',
        'driver_sessions',
        'driver_assignments',
        'booking_addons',
        'booking_qc_repair_items',
        'booking_qcs',
        'booking_dispatches',
        'booking_items',
        'vehicle_pricing_calculation_definitions',
        'business_settings',
        'vehicles',
        'users',
        'bookings',
    ] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('bookings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('customer_id')->nullable();
        $table->string('booking_number')->nullable();
        $table->string('status')->default('confirmed');
        $table->uuid('created_user_id')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->json('workflow_data')->nullable();
        $table->boolean('is_corporate_booking')->default(false);
        $table->uuid('corporate_account_id')->nullable();
        $table->uuid('employee_id')->nullable();
        $table->decimal('total_actual', 12, 2)->nullable();
        $table->decimal('base_amount', 12, 2)->default(0);
        $table->string('currency', 3)->default('LKR');
        $table->decimal('actual_distance', 8, 2)->nullable();
        $table->integer('actual_duration')->nullable();
        $table->json('pricing_snapshot')->nullable();
        $table->json('duration_metrics')->nullable();
        $table->json('distance_metrics')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('vehicles', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('title')->nullable();
        $table->string('availability_status')->nullable();
        $table->uuid('created_user_id')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('service_types', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name')->nullable();
        $table->string('context')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->string('email')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    DB::table('users')->insert([
        'id' => '00000000-0000-0000-0000-000000000001',
        'first_name' => 'QC',
        'last_name' => 'Inspector',
        'email' => 'qc@example.test',
    ]);

    Schema::create('booking_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->uuid('service_type_id')->nullable();
        $table->uuid('vehicle_group_id')->nullable();
        $table->uuid('vehicle_id')->nullable();
        $table->uuid('driver_id')->nullable();
        $table->boolean('is_self_driven')->default(true);
        $table->unsignedInteger('quantity')->default(1);
        $table->decimal('unit_price', 12, 2)->default(100);
        $table->decimal('total_price', 12, 2)->default(100);
        $table->json('pricing_breakdown')->nullable();
        $table->json('addons')->nullable();
        $table->json('metadata')->nullable();
        $table->dateTime('from_date')->nullable();
        $table->dateTime('to_date')->nullable();
        $table->unsignedInteger('duration_hours')->default(1);
        $table->unsignedInteger('duration_minutes')->default(60);
        $table->string('status')->default('confirmed');
        $table->timestamp('returned_at')->nullable();
        $table->timestamp('final_priced_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->json('lifecycle_data')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('vehicle_pricing_calculation_definitions', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('service_type_id');
        $table->string('name');
        $table->string('status')->default('active');
        $table->text('formula');
        $table->json('variables')->nullable();
        $table->json('conditions')->nullable();
        $table->string('owner_type')->nullable();
        $table->uuid('owner_id')->nullable();
        $table->integer('priority')->default(0);
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('business_settings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('type')->nullable();
        $table->text('value')->nullable();
        $table->uuid('created_user_id')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    // PricingContextPolicyService resolves a real (unmocked) WebsiteSettingsService
    // from the container, independent of the mocked instance passed to
    // BookingLifecycleService below. Force "separate" pricing mode so
    // synchronizeFinalPricing()'s normalizeCalculationParams() short-circuits
    // instead of looking up a service_types table this fixture doesn't have.
    DB::table('business_settings')->insert([
        'id' => '00000000-0000-0000-0000-000000000002',
        'type' => 'internal_pricing_mode',
        'value' => 'separate',
    ]);

    Schema::create('booking_dispatches', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->uuid('booking_item_id')->nullable();
        $table->uuid('vehicle_id')->nullable();
        $table->uuid('driver_id')->nullable();
        $table->string('dispatch_status')->default(DispatchStatus::DISPATCHED->value);
        $table->timestamp('dispatched_at')->nullable();
        $table->uuid('dispatched_by')->nullable();
        $table->timestamp('expected_return_at')->nullable();
        $table->timestamp('actual_return_at')->nullable();
        $table->uuid('returned_by')->nullable();
        $table->text('dispatch_notes')->nullable();
        $table->text('return_notes')->nullable();
        $table->decimal('fuel_level_out', 3, 1)->nullable();
        $table->decimal('fuel_level_in', 3, 1)->nullable();
        $table->integer('mileage_out')->nullable();
        $table->integer('mileage_in')->nullable();
        $table->json('vehicle_condition_out')->nullable();
        $table->json('vehicle_condition_in')->nullable();
        $table->json('damages_reported')->nullable();
        $table->json('additional_charges')->nullable();
        $table->decimal('late_return_fee', 10, 2)->default(0);
        $table->json('documents_generated')->nullable();
        $table->boolean('agreements_signed')->default(false);
        $table->boolean('is_self_driven')->default(true);
        $table->uuid('created_user_id')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
        $table->unique(['booking_id', 'booking_item_id']);
    });

    Schema::create('booking_addons', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('driver_assignments', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('driver_id')->nullable();
        $table->uuid('booking_id')->nullable();
        $table->uuid('booking_item_id')->nullable();
        $table->string('status')->default('active');
        $table->string('trip_phase')->nullable();
        $table->timestamp('trip_completed_at')->nullable();
        $table->timestamp('actual_end')->nullable();
        $table->decimal('total_distance_km', 10, 2)->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('driver_sessions', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('driver_id')->nullable();
        $table->uuid('assignment_id')->nullable();
        $table->string('status')->default('active');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('booking_qcs', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->uuid('booking_item_id')->nullable();
        $table->uuid('vehicle_id')->nullable();
        $table->uuid('dispatch_id')->nullable();
        $table->string('qc_status')->default('pending');
        $table->uuid('inspector_id')->nullable();
        $table->timestamp('inspection_started_at')->nullable();
        $table->timestamp('inspection_completed_at')->nullable();
        $table->json('interior_condition')->nullable();
        $table->json('exterior_condition')->nullable();
        $table->json('mechanical_condition')->nullable();
        $table->integer('cleanliness_rating')->nullable();
        $table->decimal('fuel_level', 3, 1)->nullable();
        $table->integer('mileage')->nullable();
        $table->json('damages_found')->nullable();
        $table->json('issues_reported')->nullable();
        $table->boolean('repair_required')->default(false);
        $table->decimal('estimated_repair_cost', 10, 2)->nullable();
        $table->text('repair_notes')->nullable();
        $table->text('qc_notes')->nullable();
        $table->json('photos')->nullable();
        $table->boolean('passed_inspection')->default(false);
        $table->boolean('requires_maintenance')->default(false);
        $table->date('next_maintenance_due')->nullable();
        $table->uuid('created_user_id')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
        $table->unique(['booking_id', 'booking_item_id']);
    });

    Schema::create('booking_qc_repair_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('qc_id');
        $table->string('item_type')->nullable();
        $table->text('description')->nullable();
        $table->decimal('cost', 10, 2)->default(0);
        $table->string('status')->default('pending');
        $table->timestamp('completed_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('audit_logs', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id')->nullable();
        $table->string('action');
        $table->string('entity');
        $table->uuid('entity_id')->nullable();
        $table->timestamp('timestamp');
        $table->json('details')->nullable();
        $table->uuid('created_user_id')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $this->invoiceService = Mockery::mock(InvoiceService::class);

    $this->loyaltyService = Mockery::mock(LoyaltyService::class);

    $this->commissionService = Mockery::mock(AgentCommissionService::class);

    $settings = Mockery::mock(WebsiteSettingsService::class);
    $settings->shouldReceive('get')->andReturnUsing(fn (string $key, mixed $default = null) => match ($key) {
        'feature_vehicle_return_management_enabled' => 'true',
        'assignment_enable_qc_stage' => 'false',
        'assignment_enable_maintenance_stage' => 'false',
        default => $default,
    });

    $this->bookingFlowService = Mockery::mock(BookingFlowService::class);
    $this->lifecycle = new BookingLifecycleService(
        Mockery::mock(AssignmentService::class),
        $this->bookingFlowService,
        Mockery::mock(CurrencyService::class),
        Mockery::mock(NotificationTriggerService::class),
        $this->invoiceService,
        Mockery::mock(AvailabilityEnforcementService::class),
        $this->loyaltyService,
        $this->commissionService,
        $settings,
        Mockery::mock(CustomerMobileActivityService::class),
        new FinalPricingTelemetryResolver(),
    );

    $this->booking = Booking::create([
        'booking_number' => 'MULTI-001',
        'status' => 'confirmed',
        'created_user_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    $this->items = collect([1, 2])->map(function (int $number): BookingItem {
        $vehicle = Vehicle::create([
            'title' => "Vehicle {$number}",
            'availability_status' => VehicleAvailabilityStatus::ON_HIRE->value,
        ]);
        $item = BookingItem::create([
            'booking_id' => $this->booking->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'confirmed',
            'quantity' => 1,
            'unit_price' => 100 * $number,
            'total_price' => 100 * $number,
        ]);
        BookingDispatch::create([
            'booking_id' => $this->booking->id,
            'booking_item_id' => $item->id,
            'vehicle_id' => $vehicle->id,
            'dispatch_status' => DispatchStatus::DISPATCHED,
            'dispatched_at' => now()->subHour(),
            'dispatched_by' => '00000000-0000-0000-0000-000000000001',
            'expected_return_at' => now()->addHour(),
            'is_self_driven' => true,
        ]);

        return $item;
    });
});

afterEach(function () {
    activity()->enableLogging();
});

it('completes items independently and generates one aggregate invoice only after every item is terminal', function () {
    $this->invoiceService->shouldReceive('generateAndSend')->once()->andReturnNull();
    $this->loyaltyService->shouldReceive('awardPointsForBooking')->once();
    $this->commissionService->shouldReceive('recordForBooking')->once();

    $first = $this->items[0];
    $second = $this->items[1];

    $firstDispatch = $this->lifecycle->processReturn($this->booking->id, [
        'booking_item_id' => $first->id,
        'returned_by' => '00000000-0000-0000-0000-000000000001',
        'actual_return_time' => now()->toIso8601String(),
        'skip_qc' => true,
    ]);

    expect($firstDispatch->booking_item_id)->toBe($first->id)
        ->and($first->fresh()->returned_at)->not->toBeNull()
        ->and($first->fresh()->final_priced_at)->not->toBeNull()
        ->and($first->fresh()->completed_at)->not->toBeNull()
        ->and($second->fresh()->completed_at)->toBeNull()
        ->and($this->booking->fresh()->status)->toBe('confirmed');

    // Retrying the same mobile/system callback must remain item-idempotent and
    // must not produce an invoice while another required item is unfinished.
    $this->lifecycle->processReturn($this->booking->id, [
        'booking_item_id' => $first->id,
        'returned_by' => '00000000-0000-0000-0000-000000000001',
        'actual_return_time' => now()->toIso8601String(),
        'skip_qc' => true,
    ]);

    expect($this->booking->fresh()->status)->toBe('confirmed')
        ->and($second->fresh()->completed_at)->toBeNull();

    $secondDispatch = $this->lifecycle->processReturn($this->booking->id, [
        'booking_item_id' => $second->id,
        'returned_by' => '00000000-0000-0000-0000-000000000001',
        'actual_return_time' => now()->toIso8601String(),
        'skip_qc' => true,
    ]);

    expect($secondDispatch->booking_item_id)->toBe($second->id)
        ->and($second->fresh()->completed_at)->not->toBeNull()
        ->and($this->booking->fresh()->status)->toBe('completed')
        ->and($this->booking->fresh()->completed_at)->not->toBeNull();

    // Aggregate retry is also idempotent: the invoice/loyalty/commission
    // expectations above remain exactly once.
    $this->lifecycle->processReturn($this->booking->id, [
        'booking_item_id' => $second->id,
        'returned_by' => '00000000-0000-0000-0000-000000000001',
        'skip_qc' => true,
    ]);

    expect(BookingDispatch::where('booking_id', $this->booking->id)->count())->toBe(2)
        ->and(BookingDispatch::where('booking_item_id', $first->id)->value('actual_return_at'))->not->toBeNull()
        ->and(BookingDispatch::where('booking_item_id', $second->id)->value('actual_return_at'))->not->toBeNull();
});

it('rejects an ambiguous multi-item action without booking_item_id', function () {
    expect(fn () => $this->lifecycle->processReturn($this->booking->id, [
        'returned_by' => '00000000-0000-0000-0000-000000000001',
    ]))->toThrow(DomainException::class, 'booking_item_id is required');
});

it('does not treat booked duration as actual duration for an overtime final calculation', function () {
    $item = $this->items[0];
    $item->update([
        'service_type_id' => '11111111-1111-4111-8111-111111111111',
        'vehicle_group_id' => '22222222-2222-4222-8222-222222222222',
        'duration_minutes' => 60,
    ]);
    DB::table('vehicle_pricing_calculation_definitions')->insert([
        'id' => '33333333-3333-4333-8333-333333333333',
        'service_type_id' => '11111111-1111-4111-8111-111111111111',
        'name' => 'Overtime final price',
        'status' => 'active',
        'formula' => 'base_charge + (overtime_minutes * overtime_rate_per_minute)',
        'variables' => json_encode([]),
        'conditions' => json_encode([]),
        'priority' => 10,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->bookingFlowService->shouldReceive('calculateDynamicPricing')->once()->andReturn([
        'total_amount' => 100,
        'pricing_scope' => [
            'calculation_definition_id' => '33333333-3333-4333-8333-333333333333',
            'calculation_definition_name' => 'Overtime final price',
        ],
        'calculation_metadata' => ['resolved_variables' => []],
        'adjustment_details' => ['adjustments' => []],
        'breakdown' => [],
    ]);

    $method = new ReflectionMethod($this->lifecycle, 'synchronizeFinalPricing');

    expect(fn () => $method->invoke($this->lifecycle, $this->booking->fresh(), [
        'booking_item' => $item->fresh(),
        'is_self_driven' => true,
        'vehicle_id' => $item->vehicle_id,
    ], null, [
        'actual_return_time' => now()->toIso8601String(),
        'activity_source' => 'system_completion',
    ], 'booking_completion'))->toThrow(DomainException::class, 'Final duration is required');
});

it('requires waiting telemetry when the selected final formula bills waiting time', function () {
    $item = $this->items[0];
    $item->update([
        'service_type_id' => '44444444-4444-4444-8444-444444444444',
        'vehicle_group_id' => '55555555-5555-4555-8555-555555555555',
    ]);
    DB::table('vehicle_pricing_calculation_definitions')->insert([
        'id' => '66666666-6666-4666-8666-666666666666',
        'service_type_id' => '44444444-4444-4444-8444-444444444444',
        'name' => 'Waiting final price',
        'status' => 'active',
        'formula' => 'base_charge + (waiting_minutes * waiting_rate_per_minute)',
        'variables' => json_encode([]),
        'conditions' => json_encode([]),
        'priority' => 10,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->bookingFlowService->shouldReceive('calculateDynamicPricing')
        ->once()
        ->withArgs(fn (array $params): bool => !array_key_exists('waiting_minutes', $params))
        ->andReturn([
            'total_amount' => 100,
            'pricing_scope' => [
                'calculation_definition_id' => '66666666-6666-4666-8666-666666666666',
                'calculation_definition_name' => 'Waiting final price',
            ],
            'calculation_metadata' => ['resolved_variables' => []],
            'adjustment_details' => ['adjustments' => []],
            'breakdown' => [],
        ]);

    $method = new ReflectionMethod($this->lifecycle, 'synchronizeFinalPricing');

    expect(fn () => $method->invoke($this->lifecycle, $this->booking->fresh(), [
        'booking_item' => $item->fresh(),
        'is_self_driven' => true,
        'vehicle_id' => $item->vehicle_id,
    ], null, [
        'actual_start_time' => now()->subHour()->toIso8601String(),
        'actual_return_time' => now()->toIso8601String(),
        'activity_source' => 'system_completion',
    ], 'booking_completion'))->toThrow(DomainException::class, 'Final waiting time is required');
});

it('preserves single-item behavior while recording the same item-owned terminal state', function () {
    $first = $this->items[0];
    $second = $this->items[1];
    BookingDispatch::where('booking_item_id', $second->id)->delete();
    Vehicle::whereKey($second->vehicle_id)->delete();
    $second->delete();

    $this->invoiceService->shouldReceive('generateAndSend')->once()->andReturnNull();

    $dispatch = $this->lifecycle->processReturn($this->booking->id, [
        'returned_by' => '00000000-0000-0000-0000-000000000001',
        'actual_return_time' => now()->toIso8601String(),
        'skip_qc' => true,
    ]);

    expect($dispatch->booking_item_id)->toBe($first->id)
        ->and($first->fresh()->returned_at)->not->toBeNull()
        ->and($first->fresh()->final_priced_at)->not->toBeNull()
        ->and($first->fresh()->completed_at)->not->toBeNull()
        ->and($first->fresh()->status)->toBe('completed')
        ->and($this->booking->fresh()->status)->toBe('completed');
});

it('owns QC and repairs per item and blocks aggregate completion until every item passes', function () {
    $this->invoiceService->shouldReceive('generateAndSend')->once()->andReturnNull();
    $this->loyaltyService->shouldReceive('awardPointsForBooking')->once();
    $this->commissionService->shouldReceive('recordForBooking')->once();

    $settings = Mockery::mock(WebsiteSettingsService::class);
    $settings->shouldReceive('get')->andReturnUsing(fn (string $key, mixed $default = null) => match ($key) {
        'feature_vehicle_return_management_enabled' => 'true',
        'assignment_enable_qc_stage' => 'true',
        'assignment_enable_maintenance_stage' => 'false',
        default => $default,
    });
    $lifecycle = new BookingLifecycleService(
        Mockery::mock(AssignmentService::class),
        Mockery::mock(BookingFlowService::class),
        Mockery::mock(CurrencyService::class),
        Mockery::mock(NotificationTriggerService::class),
        $this->invoiceService,
        Mockery::mock(AvailabilityEnforcementService::class),
        $this->loyaltyService,
        $this->commissionService,
        $settings,
        Mockery::mock(CustomerMobileActivityService::class),
        new FinalPricingTelemetryResolver(),
    );

    $first = $this->items[0];
    $second = $this->items[1];
    $actor = '00000000-0000-0000-0000-000000000001';

    $lifecycle->processReturn($this->booking->id, [
        'booking_item_id' => $first->id,
        'returned_by' => $actor,
        'actual_return_time' => now()->toIso8601String(),
    ]);

    expect($first->fresh()->returned_at)->not->toBeNull()
        ->and($first->fresh()->completed_at)->toBeNull()
        ->and($this->booking->fresh()->status)->toBe('confirmed');

    expect(fn () => $lifecycle->completeBooking($this->booking->id, [], $first->id))
        ->toThrow(DomainException::class, 'must pass QC');

    $firstQc = $lifecycle->startQCInspection($this->booking->id, $actor, $first->id);
    $firstRetry = $lifecycle->startQCInspection($this->booking->id, $actor, $first->id);
    expect($firstQc->booking_item_id)->toBe($first->id)
        ->and($firstRetry->id)->toBe($firstQc->id);

    $firstQc = $lifecycle->completeQCInspection($this->booking->id, [
        'cleanliness_rating' => 5,
        'repair_required' => false,
    ], $first->id);
    $firstQcRetry = $lifecycle->completeQCInspection($this->booking->id, [
        'cleanliness_rating' => 1,
        'repair_required' => true,
    ], $first->id);
    expect($firstQc->qc_status->value)->toBe('completed')
        ->and($firstQcRetry->id)->toBe($firstQc->id)
        ->and($firstQcRetry->qc_status->value)->toBe('completed');

    $lifecycle->completeBooking($this->booking->id, [], $first->id);
    expect($first->fresh()->completed_at)->not->toBeNull()
        ->and($second->fresh()->completed_at)->toBeNull()
        ->and($this->booking->fresh()->status)->toBe('confirmed');

    $lifecycle->processReturn($this->booking->id, [
        'booking_item_id' => $second->id,
        'returned_by' => $actor,
        'actual_return_time' => now()->toIso8601String(),
    ]);
    $secondQc = $lifecycle->startQCInspection($this->booking->id, $actor, $second->id);
    $secondQc = $lifecycle->completeQCInspection($this->booking->id, [
        'cleanliness_rating' => 2,
        'repair_required' => true,
        'issues_reported' => [['type' => 'mechanical', 'description' => 'Brake inspection']],
    ], $second->id);

    expect($secondQc->booking_item_id)->toBe($second->id)
        ->and($secondQc->qc_status->value)->toBe('issues_found');
    expect(fn () => $lifecycle->completeBooking($this->booking->id, [], $second->id))
        ->toThrow(DomainException::class, 'finish any required repairs');

    $repaired = $lifecycle->completeRepairs($this->booking->id, [], $second->id);
    $repairRetry = $lifecycle->completeRepairs($this->booking->id, [], $second->id);
    expect($repaired->qc_status->value)->toBe('completed')
        ->and($repairRetry->id)->toBe($repaired->id);

    $lifecycle->completeBooking($this->booking->id, [], $second->id);

    expect($this->booking->fresh()->status)->toBe('completed')
        ->and($second->fresh()->completed_at)->not->toBeNull()
        ->and(DB::table('booking_qcs')->where('booking_id', $this->booking->id)->count())->toBe(2)
        ->and(DB::table('booking_qcs')->where('booking_item_id', $first->id)->value('id'))->toBe($firstQc->id)
        ->and(DB::table('booking_qcs')->where('booking_item_id', $second->id)->value('id'))->toBe($secondQc->id);

    $firstDetails = $lifecycle->getQCDetails($this->booking->id, $first->id);
    $secondDetails = $lifecycle->getQCDetails($this->booking->id, $second->id);
    expect($firstDetails['id'])->toBe($firstQc->id)
        ->and($secondDetails['id'])->toBe($secondQc->id);
});

it('completes the trip and defers invoicing when active definitions exist but none match the booking', function () {
    $first = $this->items[0];
    $second = $this->items[1];
    BookingDispatch::where('booking_item_id', $second->id)->delete();
    Vehicle::whereKey($second->vehicle_id)->delete();
    $second->delete();

    $first->update([
        'service_type_id' => '77777777-7777-4777-8777-777777777777',
        'vehicle_group_id' => '88888888-8888-4888-8888-888888888888',
    ]);
    DB::table('vehicle_pricing_calculation_definitions')->insert([
        'id' => '99999999-9999-4999-8999-999999999999',
        'service_type_id' => '77777777-7777-4777-8777-777777777777',
        'name' => 'Unreachable weekday-only price',
        'status' => 'active',
        'formula' => 'base_charge',
        'variables' => json_encode([]),
        'conditions' => json_encode([['field' => 'is_weekend', 'operator' => '=', 'value' => true]]),
        'priority' => 10,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Active definitions exist for the service type, but none of them can
    // price this booking (simulates PricingDefinitionOrchestrator finding no
    // matching candidate and BookingFlowService falling back).
    $this->bookingFlowService->shouldReceive('calculateDynamicPricing')->once()->andReturn([
        'total_amount' => 0,
        'breakdown' => [],
        'calculation_metadata' => [
            'fallback_used' => true,
            'requires_quotation' => true,
            'reason' => 'No calculation definition could price this scenario',
            'reason_code' => 'no_matching_calculation_definition',
            'candidate_failures' => [
                [
                    'definition_id' => '99999999-9999-4999-8999-999999999999',
                    'reason' => 'conditions_not_met',
                    'message' => null,
                    'missing_variables' => [],
                ],
            ],
        ],
    ]);

    // The safety guard this replaces was designed to prevent exactly this:
    // invoicing off an unresolved price. It must still never fire.
    $this->invoiceService->shouldNotReceive('generateAndSend');

    $dispatch = $this->lifecycle->processReturn($this->booking->id, [
        'returned_by' => '00000000-0000-0000-0000-000000000001',
        'actual_return_time' => now()->toIso8601String(),
        'skip_qc' => true,
    ]);

    expect($dispatch->booking_item_id)->toBe($first->id)
        ->and($first->fresh()->completed_at)->not->toBeNull()
        ->and($this->booking->fresh()->status)->toBe('completed');

    $audit = data_get($first->fresh()->metadata, 'final_pricing_audit');
    expect($audit['status'])->toBe('pending_manual_pricing')
        ->and($audit['reason'])->toBe('no_matching_calculation_definition')
        ->and($audit['candidate_failures'][0]['reason'])->toBe('conditions_not_met');
});

it('resolves pending final pricing and fires the deferred invoice once retried after the fix', function () {
    $first = $this->items[0];
    $second = $this->items[1];
    BookingDispatch::where('booking_item_id', $second->id)->delete();
    Vehicle::whereKey($second->vehicle_id)->delete();
    $second->delete();

    $first->update([
        'service_type_id' => '77777777-7777-4777-8777-777777777777',
        'vehicle_group_id' => '88888888-8888-4888-8888-888888888888',
        'status' => 'completed',
        'completed_at' => now(),
        'returned_at' => now(),
        'final_priced_at' => now(),
        'metadata' => [
            'final_pricing_audit' => [
                'status' => 'pending_manual_pricing',
                'reason' => 'no_matching_calculation_definition',
                'candidate_failures' => [
                    ['definition_id' => '99999999-9999-4999-8999-999999999999', 'reason' => 'conditions_not_met'],
                ],
            ],
        ],
    ]);
    $this->booking->update(['status' => 'completed', 'completed_at' => now()]);
    DB::table('vehicle_pricing_calculation_definitions')->insert([
        'id' => '99999999-9999-4999-8999-999999999999',
        'service_type_id' => '77777777-7777-4777-8777-777777777777',
        'name' => 'Now-fixed price',
        'status' => 'active',
        'formula' => 'base_charge',
        'variables' => json_encode([]),
        'conditions' => json_encode([]),
        'priority' => 10,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Ops corrected the configuration; the same booking now resolves cleanly.
    $this->bookingFlowService->shouldReceive('calculateDynamicPricing')->once()->andReturn([
        'total_amount' => 120,
        'pricing_scope' => [
            'calculation_definition_id' => '99999999-9999-4999-8999-999999999999',
            'calculation_definition_name' => 'Now-fixed price',
        ],
        'calculation_metadata' => ['resolved_variables' => []],
        'adjustment_details' => ['adjustments' => []],
        'breakdown' => [],
    ]);
    $this->invoiceService->shouldReceive('generateAndSend')->once()->andReturnNull();

    $result = $this->lifecycle->retryPendingFinalPricing($first->id);

    expect($result['retried'])->toBeTrue()
        ->and($result['resolved'])->toBeTrue();

    $audit = data_get($first->fresh()->metadata, 'final_pricing_audit');
    expect($audit['status'])->toBe('calculated');
});
