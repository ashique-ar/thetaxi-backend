<?php

namespace Tests\Unit;

use App\Services\BookingFlowService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Tests\TestCase;

class TourLegacyBookingBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('service_types', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('code');
            $table->string('name');
            $table->string('slug');
            $table->string('pricing_basis')->default('vehicle_group');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });
        DB::table('service_types')->insert([
            'id' => '1a0f6c7e-50f9-4210-a898-c2a5d0c804ad', 'code' => 'tours', 'name' => 'Tours', 'slug' => 'tours', 'pricing_basis' => 'vehicle_class',
        ]);
    }

    public function test_class_service_cannot_be_confirmed_through_the_group_only_booking_path(): void
    {
        $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
        $this->expectException(ValidationException::class);
        $service->confirmBooking(['service_type_id' => '1a0f6c7e-50f9-4210-a898-c2a5d0c804ad']);
    }

    public function test_nested_class_booking_cannot_be_submitted_through_the_group_only_approval_path(): void
    {
        $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
        $this->expectException(ValidationException::class);
        $service->submitBookingForApproval(['booking_items' => [['service_type' => 'tours']]]);
    }

    public function test_an_item_service_alias_cannot_be_hidden_by_a_parent_service_id(): void
    {
        DB::table('service_types')->insert([
            'id' => 'ordinary', 'code' => 'taxi', 'name' => 'Taxi', 'slug' => 'taxi',
        ]);
        $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
        $this->expectException(ValidationException::class);
        $service->submitBookingForApproval(['service_type_id' => 'ordinary', 'booking_items' => [['service_type' => 'tours']]]);
    }

    public function test_disabled_class_service_cannot_bypass_the_tours_booking_boundary(): void
    {
        DB::table('service_types')->update(['is_active' => false, 'deleted_at' => now()]);
        $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
        $this->expectException(ValidationException::class);
        $service->submitBookingForApproval(['service_type' => 'tours']);
    }
}
