<?php

use App\Http\Controllers\Api\ReportsController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('returns executive overview summary, fleet, operations, revenue margin, and comparison', function () {
    Schema::create('customers', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('type')->default('individual');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('vehicles', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('availability_status')->default('available');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('service_types', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('bookings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('customer_id')->nullable();
        $table->string('status')->default('booking_pending');
        $table->string('payment_status')->nullable();
        $table->string('currency', 3)->default('LKR');
        $table->decimal('total_actual', 12, 2)->nullable();
        $table->decimal('driver_cost', 12, 2)->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('booking_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->uuid('vehicle_id')->nullable();
        $table->uuid('service_type_id')->nullable();
        $table->decimal('total_price', 12, 2)->nullable();
        $table->string('currency', 3)->nullable();
        $table->timestamp('from_date')->nullable();
        $table->timestamp('to_date')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    DB::table('service_types')->insert([
        'id' => '00000000-0000-4000-8000-000000000001',
        'name' => 'Airport Transfer',
    ]);
    DB::table('vehicles')->insert([
        ['id' => '00000000-0000-4000-8000-000000000011', 'availability_status' => 'available', 'is_active' => 1],
        ['id' => '00000000-0000-4000-8000-000000000012', 'availability_status' => 'unavailable_maintenance', 'is_active' => 1],
    ]);
    DB::table('customers')->insert([
        'id' => '00000000-0000-4000-8000-000000000021',
        'type' => 'corporate',
        'is_active' => 1,
        'created_at' => '2026-11-10 10:00:00',
    ]);
    DB::table('bookings')->insert([
        [
            'id' => '00000000-0000-4000-8000-000000000031',
            'customer_id' => '00000000-0000-4000-8000-000000000021',
            'status' => 'completed',
            'payment_status' => 'invoiced_corporate',
            'currency' => 'LKR',
            'total_actual' => 15000,
            'driver_cost' => 4500,
            'completed_at' => '2026-11-15 12:00:00',
            'created_at' => '2026-11-15 10:00:00',
        ],
        [
            'id' => '00000000-0000-4000-8000-000000000032',
            'customer_id' => '00000000-0000-4000-8000-000000000021',
            'status' => 'return_overdue',
            'payment_status' => 'pending',
            'currency' => 'LKR',
            'total_actual' => null,
            'driver_cost' => null,
            'completed_at' => null,
            'created_at' => '2026-11-16 10:00:00',
        ],
        [
            'id' => '00000000-0000-4000-8000-000000000033',
            'customer_id' => '00000000-0000-4000-8000-000000000021',
            'status' => 'completed',
            'payment_status' => 'invoiced_corporate',
            'currency' => 'LKR',
            'total_actual' => 10000,
            'driver_cost' => 3000,
            'completed_at' => '2026-10-15 12:00:00',
            'created_at' => '2026-10-15 10:00:00',
        ],
    ]);
    DB::table('booking_items')->insert([
        'id' => '00000000-0000-4000-8000-000000000041',
        'booking_id' => '00000000-0000-4000-8000-000000000031',
        'vehicle_id' => '00000000-0000-4000-8000-000000000011',
        'service_type_id' => '00000000-0000-4000-8000-000000000001',
        'total_price' => 15000,
        'currency' => 'LKR',
        'from_date' => '2026-11-15 10:00:00',
        'to_date' => '2026-11-15 12:00:00',
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('can')->andReturn(true);
    $user->shouldReceive('getAllPermissions')->andReturn(collect());
    $user->shouldReceive('getAuthIdentifier')->andReturn('user-1');
    request()->setUserResolver(fn () => $user);

    $request = Request::create('/api/reports/executive-overview', 'GET', [
        'date_from' => '2026-11-01',
        'date_to' => '2026-11-30',
        'compare_date_from' => '2026-10-01',
        'compare_date_to' => '2026-10-31',
    ]);
    $request->setUserResolver(fn () => $user);

    $response = app(ReportsController::class)->getExecutiveOverview($request);
    $payload = $response->getData(true);

    expect($payload['status'])->toBe('success')
        ->and($payload['data']['summary']['total_bookings'])->toBe(2)
        ->and($payload['data']['summary']['completed_bookings'])->toBe(1)
        ->and($payload['data']['summary']['revenue_by_currency'][0]['revenue'])->toBe(15000)
        ->and($payload['data']['summary']['revenue_by_currency'][0]['gross_margin'])->toBe(10500)
        ->and($payload['data']['summary']['receivables_by_currency'][0]['unpaid_count'])->toBe(1)
        ->and($payload['data']['summary']['receivables_by_currency'][0]['unpaid_amount'])->toBe(15000)
        ->and($payload['data']['operations']['overdue_returns_count'])->toBe(1)
        ->and($payload['data']['operations']['open_inquiries_count'])->toBe(0)
        ->and($payload['data']['fleet']['available'])->toBe(1)
        ->and($payload['data']['fleet']['in_maintenance'])->toBe(1)
        ->and($payload['data']['comparison']['total_bookings'])->toBe(1)
        ->and($payload['data']['comparison']['revenue_by_currency'][0]['revenue'])->toBe(10000);
});
