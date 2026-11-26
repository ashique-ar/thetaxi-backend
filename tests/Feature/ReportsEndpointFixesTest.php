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
