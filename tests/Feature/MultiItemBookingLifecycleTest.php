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
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    activity()->disableLogging();

    foreach ([
        'audit_logs',
        'booking_addons',
        'booking_qcs',
        'booking_dispatches',
        'booking_items',
        'vehicles',
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

    Schema::create('booking_qcs', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->string('qc_status')->nullable();
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

    $this->lifecycle = new BookingLifecycleService(
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
