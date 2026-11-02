<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\BookingAssignmentController;
use App\Models\Booking\BookingItem;
use App\Models\Vehicle\VehicleAssignment;
use App\Services\AssignmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TourClassAssignmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        activity()->disableLogging();
        Schema::create('service_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('pricing_basis')->default('vehicle_group');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('bookings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('customer_id')->nullable();
            $table->unsignedInteger('passenger_count')->default(1);
            $table->unsignedInteger('luggage_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('booking_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('booking_id');
            $table->uuid('service_type_id');
            $table->uuid('vehicle_class_id')->nullable();
            $table->string('pricing_basis')->default('vehicle_group');
            $table->uuid('vehicle_group_id')->nullable();
            $table->uuid('vehicle_id')->nullable();
            $table->uuid('driver_id')->nullable();
            $table->dateTime('from_date')->nullable();
            $table->dateTime('to_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('vehicle_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('class_id')->nullable();
            $table->unsignedInteger('passengers_count')->nullable();
            $table->unsignedInteger('hand_luggages')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('vehicle_group_id')->nullable();
            $table->uuid('class_id')->nullable();
            $table->string('title')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('service_vehicle_class_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('service_type_id');
            $table->uuid('vehicle_class_id');
            $table->unsignedSmallInteger('max_passengers');
            $table->unsignedSmallInteger('max_luggage');
            $table->boolean('is_active')->default(true);
            $table->json('allowed_substitute_class_ids')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('vehicle_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('booking_id');
            $table->uuid('vehicle_id');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
        DB::table('service_types')->insert(['id' => '10000000-0000-4000-8000-000000000001', 'name' => 'Tours', 'pricing_basis' => 'vehicle_class']);
        DB::table('bookings')->insert(['id' => '20000000-0000-4000-8000-000000000001', 'passenger_count' => 3, 'luggage_count' => 2]);
        DB::table('booking_items')->insert(['id' => '30000000-0000-4000-8000-000000000001', 'booking_id' => '20000000-0000-4000-8000-000000000001', 'service_type_id' => '10000000-0000-4000-8000-000000000001', 'vehicle_class_id' => '40000000-0000-4000-8000-000000000001', 'pricing_basis' => 'vehicle_class']);
        DB::table('vehicle_groups')->insert(['id' => '50000000-0000-4000-8000-000000000001', 'class_id' => '40000000-0000-4000-8000-000000000002', 'passengers_count' => 4, 'hand_luggages' => 3]);
        DB::table('vehicles')->insert(['id' => '60000000-0000-4000-8000-000000000001', 'vehicle_group_id' => '50000000-0000-4000-8000-000000000001', 'status' => 'active']);
        DB::table('service_vehicle_class_settings')->insert([
            ['id' => '70000000-0000-4000-8000-000000000001', 'service_type_id' => '10000000-0000-4000-8000-000000000001', 'vehicle_class_id' => '40000000-0000-4000-8000-000000000001', 'max_passengers' => 4, 'max_luggage' => 3, 'is_active' => true, 'allowed_substitute_class_ids' => json_encode([])],
            ['id' => '70000000-0000-4000-8000-000000000002', 'service_type_id' => '10000000-0000-4000-8000-000000000001', 'vehicle_class_id' => '40000000-0000-4000-8000-000000000002', 'max_passengers' => 4, 'max_luggage' => 3, 'is_active' => true, 'allowed_substitute_class_ids' => json_encode([])],
        ]);
    }

    public function test_unapproved_class_substitution_is_rejected_before_assignment(): void
    {
        $assignments = $this->createMock(AssignmentService::class);
        $assignments->expects(self::never())->method('createVehicleAssignment');
        $controller = new BookingAssignmentController($assignments);
        $response = $controller->createBookingAssignment(
            Request::create('/api/booking-items/id/assign', 'POST', ['vehicle_id' => '60000000-0000-4000-8000-000000000001']),
            BookingItem::findOrFail('30000000-0000-4000-8000-000000000001'),
        );

        self::assertSame(422, $response->getStatusCode(), json_encode($response->getData(true)));
        self::assertSame('40000000-0000-4000-8000-000000000001', DB::table('booking_items')->value('vehicle_class_id'));
    }

    public function test_same_class_assignment_sets_operational_group_and_preserves_purchased_class(): void
    {
        DB::table('vehicle_groups')->where('id', '50000000-0000-4000-8000-000000000001')->update(['class_id' => '40000000-0000-4000-8000-000000000001']);
        $assignment = new VehicleAssignment(['requires_approval' => false]);
        $assignments = $this->createMock(AssignmentService::class);
        $assignments->expects(self::once())->method('createVehicleAssignment')->willReturn($assignment);
        $controller = new BookingAssignmentController($assignments);
        $response = $controller->createBookingAssignment(
            Request::create('/api/booking-items/id/assign', 'POST', ['vehicle_id' => '60000000-0000-4000-8000-000000000001']),
            BookingItem::findOrFail('30000000-0000-4000-8000-000000000001'),
        );

        self::assertSame(201, $response->getStatusCode(), json_encode($response->getData(true)));
        self::assertSame('40000000-0000-4000-8000-000000000001', DB::table('booking_items')->value('vehicle_class_id'));
        self::assertSame('50000000-0000-4000-8000-000000000001', DB::table('booking_items')->value('vehicle_group_id'));
        self::assertSame('60000000-0000-4000-8000-000000000001', DB::table('booking_items')->value('vehicle_id'));
    }
}
