<?php

use App\Http\Controllers\Api\Corporate\CorporateBookingController;
use App\Models\Booking\BookingItem;
use App\Services\CorporateBookingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    activity()->disableLogging();

    Schema::dropIfExists('booking_items');
    Schema::dropIfExists('bookings');
    Schema::dropIfExists('corporate_divisions');
    Schema::dropIfExists('corporate_departments');

    Schema::create('bookings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('corporate_account_id')->nullable();
        $table->uuid('employee_id')->nullable();
        $table->boolean('is_corporate_booking')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('booking_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('corporate_departments', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('corporate_id');
        $table->string('name');
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('corporate_divisions', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('department_id');
        $table->string('name');
        $table->timestamps();
        $table->softDeletes();
    });
});

it('keeps corporate booking item queries isolated to the authenticated company', function () {
    DB::table('bookings')->insert([
        ['id' => 'booking-a', 'corporate_account_id' => 'company-a', 'employee_id' => 'employee-a', 'is_corporate_booking' => true],
        ['id' => 'booking-b', 'corporate_account_id' => 'company-b', 'employee_id' => 'employee-b', 'is_corporate_booking' => true],
    ]);
    DB::table('booking_items')->insert([
        ['id' => 'item-a', 'booking_id' => 'booking-a'],
        ['id' => 'item-b', 'booking_id' => 'booking-b'],
    ]);

    $service = app(CorporateBookingService::class);
    $method = new ReflectionMethod($service, 'corporateBookingItemQuery');
    $query = $method->invoke($service, 'company-a');
    $query->setEagerLoads([]);

    expect($query->pluck('booking_items.id')->all())->toBe(['item-a']);
});

it('rejects department and division filters owned by another company', function () {
    DB::table('corporate_departments')->insert([
        ['id' => 'department-a', 'corporate_id' => 'company-a', 'name' => 'A'],
        ['id' => 'department-b', 'corporate_id' => 'company-b', 'name' => 'B'],
    ]);
    DB::table('corporate_divisions')->insert([
        ['id' => 'division-a', 'department_id' => 'department-a', 'name' => 'A1'],
        ['id' => 'division-b', 'department_id' => 'department-b', 'name' => 'B1'],
    ]);

    $controller = app(CorporateBookingController::class);
    $method = new ReflectionMethod($controller, 'validateCorporateFilters');
    $request = Request::create('/api/corporate/bookings', 'GET');
    $request->corporate_id = 'company-a';

    expect(fn () => $method->invoke($controller, $request, ['department_id' => 'department-b']))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => $method->invoke($controller, $request, ['division_id' => 'division-b']))
        ->toThrow(ModelNotFoundException::class);

    expect(fn () => $method->invoke($controller, $request, [
        'department_id' => 'department-a',
        'division_id' => 'division-a',
    ]))->not->toThrow(ModelNotFoundException::class);
});

it('keeps self-only and company-wide scope decisions on the server permission boundary', function () {
    $controller = app(CorporateBookingController::class);
    $method = new ReflectionMethod($controller, 'bookingScope');
    $request = Request::create('/api/corporate/bookings', 'GET');

    $employee = Mockery::mock();
    $employee->shouldReceive('can')->with('view_all_bookings')->andReturnFalse();
    $request->setUserResolver(fn () => $employee);
    expect($method->invoke($controller, $request))->toBe('employee');

    $coordinator = Mockery::mock();
    $coordinator->shouldReceive('can')->with('view_all_bookings')->andReturnTrue();
    $request->setUserResolver(fn () => $coordinator);
    expect($method->invoke($controller, $request))->toBe('company');
});

it('resolves pricing visibility from finance or configured coordinator permissions only', function () {
    $controller = app(CorporateBookingController::class);
    $method = new ReflectionMethod($controller, 'canViewPayments');
    $request = Request::create('/api/corporate/bookings/booking-a', 'GET');

    $finance = Mockery::mock();
    $finance->shouldReceive('can')->with('view_payments')->andReturnTrue();
    $request->setUserResolver(fn () => $finance);
    $request->attributes->set('corporate_employee', (object) ['corporate' => (object) [
        'coordinator_can_view_payments' => false,
    ]]);
    expect($method->invoke($controller, $request))->toBeTrue();

    $coordinator = Mockery::mock();
    $coordinator->shouldReceive('can')->with('view_payments')->andReturnFalse();
    $coordinator->shouldReceive('can')->with('create_bookings_for_others')->andReturnTrue();
    $request->setUserResolver(fn () => $coordinator);
    $request->attributes->set('corporate_employee', (object) ['corporate' => (object) [
        'coordinator_can_view_payments' => true,
    ]]);
    expect($method->invoke($controller, $request))->toBeTrue();

    $employee = Mockery::mock();
    $employee->shouldReceive('can')->with('view_payments')->andReturnFalse();
    $employee->shouldReceive('can')->with('create_bookings_for_others')->andReturnFalse();
    $request->setUserResolver(fn () => $employee);
    expect($method->invoke($controller, $request))->toBeFalse();
});

it('omits payment and contractual pricing fields from non-pricing corporate projections', function () {
    $item = (new ReflectionClass(BookingItem::class))->newInstanceWithoutConstructor();
    $item->setRawAttributes([
        'id' => 'item-a',
        'status' => 'confirmed',
        'total_price' => 5000,
        'currency' => 'LKR',
        'pricing_breakdown' => json_encode(['pricing_scope' => 'corporate']),
    ]);
    foreach (['booking', 'serviceType', 'vehicleGroup', 'vehicle', 'driver'] as $relation) {
        $item->setRelation($relation, null);
    }

    $service = app(CorporateBookingService::class);
    $method = new ReflectionMethod($service, 'mapBookingItem');
    $withoutPricing = $method->invoke($service, $item, false);
    $withPricing = $method->invoke($service, $item, true);
    $pricingFields = [
        'total_cost',
        'currency',
        'payment_status',
        'payment_method',
        'payment_responsibility',
        'payment_collection_method',
        'payment_collection_status',
        'pricing_scope',
    ];

    expect($withoutPricing)->not->toHaveKeys($pricingFields)
        ->and($withPricing)->toHaveKeys($pricingFields)
        ->and($withoutPricing)->not->toHaveKeys([
            'route_points',
            'tracking_points',
            'driver_sessions',
            'actual_route',
            'pricing_breakdown',
            'metadata',
        ]);
});
