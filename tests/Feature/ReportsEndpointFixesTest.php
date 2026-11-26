<?php

use App\Http\Controllers\Api\ReportsController;
use App\Models\Booking\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

it('accepts string boolean values for unpriced_only in report filters', function () {
    $controller = app(ReportsController::class);
    $parseFilters = new ReflectionMethod($controller, 'parseFilters');

    $trueFilters = $parseFilters->invoke($controller, Request::create('/api/reports/bookings', 'GET', ['unpriced_only' => 'true']));
    $falseFilters = $parseFilters->invoke($controller, Request::create('/api/reports/bookings', 'GET', ['unpriced_only' => 'false']));

    expect($trueFilters['unpriced_only'] ?? null)->toBeTrue()
        ->and($falseFilters)->not->toHaveKey('unpriced_only');

    expect(fn () => $parseFilters->invoke($controller, Request::create('/api/reports/bookings', 'GET', ['unpriced_only' => 'invalid'])))
        ->toThrow(ValidationException::class);
});

it('aggregates customer demographics by country through addresses without customers.country_id', function () {
    Schema::create('countries', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('customers', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('type')->default('individual');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('addresses', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('addressable_type');
        $table->uuid('addressable_id');
        $table->uuid('country_id')->nullable();
        $table->boolean('is_default')->default(true);
        $table->timestamps();
    });
    Schema::create('bookings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('customer_id')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });

    DB::table('countries')->insert(['id' => '00000000-0000-4000-8000-000000000001', 'name' => 'Sri Lanka', 'is_active' => 1]);
    DB::table('customers')->insert(['id' => '00000000-0000-4000-8000-000000000010', 'type' => 'individual', 'is_active' => 1]);
    // Two addresses for the same customer in the same country should count as 1 customer.
    DB::table('addresses')->insert([
        ['id' => '00000000-0000-4000-8000-000000000020', 'addressable_type' => Customer::class, 'addressable_id' => '00000000-0000-4000-8000-000000000010', 'country_id' => '00000000-0000-4000-8000-000000000001', 'is_default' => 1],
        ['id' => '00000000-0000-4000-8000-000000000021', 'addressable_type' => Customer::class, 'addressable_id' => '00000000-0000-4000-8000-000000000010', 'country_id' => '00000000-0000-4000-8000-000000000001', 'is_default' => 0],
    ]);
    DB::table('bookings')->insert(['id' => '00000000-0000-4000-8000-000000000030', 'customer_id' => '00000000-0000-4000-8000-000000000010', 'is_active' => 1]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('can')->andReturn(true);
    request()->setUserResolver(fn () => $user);

    $controller = app(ReportsController::class);
    $demographics = (new ReflectionMethod($controller, 'getCustomerDemographics'))->invoke($controller, []);

    expect($demographics['by_country'])->toBe([
        ['country' => 'Sri Lanka', 'count' => 1],
    ]);
});

it('returns booking report currency summaries without object property access errors', function () {
    Schema::create('users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('customers', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('vehicles', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('title')->nullable();
        $table->string('license_plate')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('bookings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('booking_number')->nullable();
        $table->uuid('customer_id')->nullable();
        $table->uuid('agent_id')->nullable();
        $table->decimal('total_actual', 12, 2)->nullable();
        $table->string('currency')->nullable();
        $table->string('status')->nullable();
        $table->string('payment_status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('booking_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->uuid('vehicle_id')->nullable();
        $table->date('from_date')->nullable();
        $table->date('to_date')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    DB::table('bookings')->insert([
        'id' => '00000000-0000-4000-8000-000000000101',
        'booking_number' => 'BK-1001',
        'total_actual' => 12500.00,
        'currency' => 'lkr',
        'status' => 'completed',
        'payment_status' => 'paid',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = app(ReportsController::class)->getBookingReports(Request::create('/api/reports/bookings', 'GET'));
    $payload = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['meta']['summary']['by_currency'])->toBe([
            [
                'currency' => 'LKR',
                'bookings' => 1,
                'amount' => 12500,
                'priced_bookings_count' => 1,
                'unpriced_bookings_count' => 0,
            ],
        ]);
});
