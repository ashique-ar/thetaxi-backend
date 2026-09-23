<?php

use App\Http\Controllers\Api\Corporate\CorporateBookingController;
use App\Http\Requests\Corporate\CorporateReportFiltersRequest;
use App\Http\Requests\Corporate\StoreCorporateBookingRequest;
use App\Models\Booking\BookingItem;
use App\Services\CorporateBookingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    activity()->disableLogging();

    Schema::dropIfExists('booking_items');
    Schema::dropIfExists('bookings');
    Schema::dropIfExists('corporate_divisions');
    Schema::dropIfExists('corporate_departments');
    Schema::dropIfExists('corporate_employees');
    Schema::dropIfExists('corporate_employee_locations');
    Schema::dropIfExists('service_packages');

    Schema::create('bookings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('corporate_account_id')->nullable();
        $table->uuid('employee_id')->nullable();
        $table->uuid('corporate_department_id')->nullable();
        $table->uuid('corporate_division_id')->nullable();
        $table->uuid('created_by_user_id')->nullable();
        $table->boolean('is_corporate_booking')->default(false);
        $table->string('status')->nullable();
        $table->decimal('total_estimated', 12, 2)->nullable();
        $table->decimal('total_actual', 12, 2)->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('booking_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->dateTime('from_date')->nullable();
        $table->dateTime('to_date')->nullable();
        $table->string('status')->nullable();
        $table->decimal('total_price', 12, 2)->nullable();
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
    Schema::create('corporate_employees', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('corporate_id');
        $table->uuid('user_id');
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('corporate_employee_locations', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('corporate_employee_id');
        $table->string('label')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('service_packages', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('service_type_id');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
});

it('validates general and employee booking parties inside the authenticated corporate', function () {
    $companyA = '20000000-0000-4000-8000-000000000001';
    $companyB = '20000000-0000-4000-8000-000000000002';
    $employeeA = '30000000-0000-4000-8000-000000000001';
    $employeeB = '30000000-0000-4000-8000-000000000002';
    $departmentA = '40000000-0000-4000-8000-000000000001';
    $departmentB = '40000000-0000-4000-8000-000000000002';
    $divisionA = '50000000-0000-4000-8000-000000000001';
    $divisionB = '50000000-0000-4000-8000-000000000002';

    DB::table('corporate_employees')->insert([
        ['id' => $employeeA, 'corporate_id' => $companyA, 'user_id' => '60000000-0000-4000-8000-000000000001'],
        ['id' => $employeeB, 'corporate_id' => $companyB, 'user_id' => '60000000-0000-4000-8000-000000000002'],
    ]);
    DB::table('corporate_departments')->insert([
        ['id' => $departmentA, 'corporate_id' => $companyA, 'name' => 'A'],
        ['id' => $departmentB, 'corporate_id' => $companyB, 'name' => 'B'],
    ]);
    DB::table('corporate_divisions')->insert([
        ['id' => $divisionA, 'department_id' => $departmentA, 'name' => 'A1'],
        ['id' => $divisionB, 'department_id' => $departmentB, 'name' => 'B1'],
    ]);

    $validate = function (array $payload) use ($companyA): StoreCorporateBookingRequest {
        $request = StoreCorporateBookingRequest::create('/api/corporate/bookings', 'POST', $payload);
        $request->attributes->set('corporate_id', $companyA);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();
        return $request;
    };

    $general = $validate([
        'booking_party_mode' => 'general',
        'corporate_contact' => ['name' => 'Visitor A', 'phone' => '+94770000001'],
        'vehicle_id' => '70000000-0000-4000-8000-000000000001',
        'driver_id' => '80000000-0000-4000-8000-000000000001',
    ]);
    expect($general->validated('booking_party_mode'))->toBe('general')
        ->and($general->validated('corporate_contact.name'))->toBe('Visitor A')
        ->and($general->validated())->not->toHaveKeys(['vehicle_id', 'driver_id']);

    expect(fn () => $validate([
        'booking_party_mode' => 'general',
        'corporate_contact' => ['name' => 'Missing Phone'],
    ]))->toThrow(ValidationException::class);

    expect(fn () => $validate([
        'booking_party_mode' => 'employee',
        'employee_id' => $employeeB,
        'corporate_department_id' => $departmentB,
        'corporate_division_id' => $divisionB,
    ]))->toThrow(ValidationException::class);

    expect(fn () => $validate([
        'booking_party_mode' => 'employee',
        'employee_id' => $employeeA,
        'corporate_department_id' => $departmentA,
        'corporate_division_id' => $divisionA,
    ]))->not->toThrow(ValidationException::class);
});

it('rejects foreign stop contacts and packages from another service', function () {
    $companyA = '20000000-0000-4000-8000-000000000001';
    $companyB = '20000000-0000-4000-8000-000000000002';
    $employeeA = '30000000-0000-4000-8000-000000000001';
    $employeeB = '30000000-0000-4000-8000-000000000002';
    $locationA = '40000000-0000-4000-8000-000000000001';
    $locationB = '40000000-0000-4000-8000-000000000002';
    $serviceA = '50000000-0000-4000-8000-000000000001';
    $serviceB = '50000000-0000-4000-8000-000000000002';
    $packageA = '60000000-0000-4000-8000-000000000001';

    DB::table('corporate_employees')->insert([
        ['id' => $employeeA, 'corporate_id' => $companyA, 'user_id' => '70000000-0000-4000-8000-000000000001'],
        ['id' => $employeeB, 'corporate_id' => $companyB, 'user_id' => '70000000-0000-4000-8000-000000000002'],
    ]);
    DB::table('corporate_employee_locations')->insert([
        ['id' => $locationA, 'corporate_employee_id' => $employeeA],
        ['id' => $locationB, 'corporate_employee_id' => $employeeB],
    ]);
    DB::table('service_packages')->insert([
        'id' => $packageA,
        'service_type_id' => $serviceA,
        'is_active' => true,
    ]);

    $corporate = new \App\Models\Corporate\Corporate();
    $corporate->setRawAttributes(['id' => $companyA]);
    $service = app(CorporateBookingService::class);
    $stops = new ReflectionMethod($service, 'validateStopOwnership');
    $packages = new ReflectionMethod($service, 'validateServicePackage');

    expect(fn () => $stops->invoke($service, $corporate, [
        'primary_pickup_contact' => ['employee_id' => $employeeA, 'employee_location_id' => $locationA],
    ]))->not->toThrow(Throwable::class)
        ->and(fn () => $stops->invoke($service, $corporate, [
            'ordered_additional_stops' => [['employee_id' => $employeeB, 'employee_location_id' => $locationB]],
        ]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class)
        ->and(fn () => $packages->invoke($service, $serviceA, $packageA))->not->toThrow(Throwable::class)
        ->and(fn () => $packages->invoke($service, $serviceB, $packageA))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
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

it('shows an actor employee bookings and general bookings they created only in the active corporate', function () {
    DB::table('bookings')->insert([
        ['id' => 'booking-own', 'corporate_account_id' => 'company-a', 'employee_id' => 'actor-a', 'is_corporate_booking' => true],
        ['id' => 'booking-general', 'corporate_account_id' => 'company-a', 'created_by_user_id' => 'actor-a', 'is_corporate_booking' => true],
        ['id' => 'booking-foreign', 'corporate_account_id' => 'company-b', 'created_by_user_id' => 'actor-a', 'is_corporate_booking' => true],
    ]);
    DB::table('booking_items')->insert([
        ['id' => 'item-own', 'booking_id' => 'booking-own'],
        ['id' => 'item-general', 'booking_id' => 'booking-general'],
        ['id' => 'item-foreign', 'booking_id' => 'booking-foreign'],
    ]);

    $service = app(CorporateBookingService::class);
    $method = new ReflectionMethod($service, 'corporateActorBookingItemQuery');
    $query = $method->invoke($service, 'actor-a', 'company-a');
    $query->setEagerLoads([]);

    expect($query->orderBy('booking_items.id')->pluck('booking_items.id')->all())
        ->toBe(['item-general', 'item-own']);
});

it('uses one corporate department status and travel window for multi trip rows and summary bookings', function () {
    DB::table('bookings')->insert([
        [
            'id' => '10000000-0000-4000-8000-000000000001',
            'corporate_account_id' => '20000000-0000-4000-8000-000000000001',
            'employee_id' => '30000000-0000-4000-8000-000000000001',
            'corporate_department_id' => '40000000-0000-4000-8000-000000000001',
            'is_corporate_booking' => true,
            'status' => 'completed',
            'total_estimated' => 300,
            'total_actual' => 320,
        ],
        [
            'id' => '10000000-0000-4000-8000-000000000002',
            'corporate_account_id' => '20000000-0000-4000-8000-000000000002',
            'employee_id' => '30000000-0000-4000-8000-000000000002',
            'corporate_department_id' => '40000000-0000-4000-8000-000000000001',
            'is_corporate_booking' => true,
            'status' => 'completed',
            'total_estimated' => 900,
            'total_actual' => null,
        ],
    ]);
    DB::table('booking_items')->insert([
        [
            'id' => '50000000-0000-4000-8000-000000000001',
            'booking_id' => '10000000-0000-4000-8000-000000000001',
            'from_date' => '2026-07-10 09:00:00',
            'to_date' => '2026-07-10 10:00:00',
            'status' => 'completed',
            'total_price' => 100,
        ],
        [
            'id' => '50000000-0000-4000-8000-000000000002',
            'booking_id' => '10000000-0000-4000-8000-000000000001',
            'from_date' => '2026-08-10 09:00:00',
            'to_date' => '2026-08-10 10:00:00',
            'status' => 'completed',
            'total_price' => 200,
        ],
        [
            'id' => '50000000-0000-4000-8000-000000000003',
            'booking_id' => '10000000-0000-4000-8000-000000000002',
            'from_date' => '2026-08-11 09:00:00',
            'to_date' => '2026-08-11 10:00:00',
            'status' => 'completed',
            'total_price' => 900,
        ],
    ]);

    $filters = [
        'status' => 'completed',
        'department_id' => '40000000-0000-4000-8000-000000000001',
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
    ];
    $service = app(CorporateBookingService::class);
    $queryMethod = new ReflectionMethod($service, 'corporateBookingItemQuery');
    $filterMethod = new ReflectionMethod($service, 'applyBookingItemFilters');
    $rows = $queryMethod->invoke($service, '20000000-0000-4000-8000-000000000001');
    $rows->setEagerLoads([]);
    $filterMethod->invoke($service, $rows, $filters);
    $stats = $service->getBookingSummaryStats('20000000-0000-4000-8000-000000000001', $filters);

    expect($rows->pluck('booking_items.id')->all())
        ->toBe(['50000000-0000-4000-8000-000000000002'])
        ->and($stats['total_bookings'])->toBe(1)
        ->and($stats['trip_count'])->toBe(1)
        ->and($stats['total_cost'])->toBe(300.0)
        ->and($stats['estimated_value'])->toBe(300.0)
        ->and($stats['finalized_value'])->toBe(320.0)
        ->and($stats['finalized_booking_count'])->toBe(1)
        ->and($stats['unfinalized_booking_count'])->toBe(0)
        ->and($stats['by_status'])->toBe(['completed' => 1]);
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

it('validates report departments and divisions against the authenticated corporate', function () {
    DB::table('corporate_departments')->insert([
        ['id' => '40000000-0000-4000-8000-000000000001', 'corporate_id' => '20000000-0000-4000-8000-000000000001', 'name' => 'Company A'],
        ['id' => '40000000-0000-4000-8000-000000000002', 'corporate_id' => '20000000-0000-4000-8000-000000000002', 'name' => 'Company B'],
    ]);
    DB::table('corporate_divisions')->insert([
        ['id' => '60000000-0000-4000-8000-000000000001', 'department_id' => '40000000-0000-4000-8000-000000000001', 'name' => 'A1'],
        ['id' => '60000000-0000-4000-8000-000000000002', 'department_id' => '40000000-0000-4000-8000-000000000002', 'name' => 'B1'],
    ]);

    $foreign = CorporateReportFiltersRequest::create('/api/corporate/reports/summary-stats', 'GET', [
        'department_id' => '40000000-0000-4000-8000-000000000002',
        'division_id' => '60000000-0000-4000-8000-000000000002',
    ]);
    $foreign->corporate_id = '20000000-0000-4000-8000-000000000001';
    $foreign->setContainer(app())->setRedirector(app('redirect'));

    expect(fn() => $foreign->validateResolved())->toThrow(ValidationException::class);

    $owned = CorporateReportFiltersRequest::create('/api/corporate/reports/summary-stats', 'GET', [
        'department_id' => '40000000-0000-4000-8000-000000000001',
        'division_id' => '60000000-0000-4000-8000-000000000001',
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
        'per_page' => 25,
    ]);
    $owned->corporate_id = '20000000-0000-4000-8000-000000000001';
    $owned->setContainer(app())->setRedirector(app('redirect'));

    expect(fn() => $owned->validateResolved())->not->toThrow(ValidationException::class)
        ->and($owned->validated('department_id'))->toBe('40000000-0000-4000-8000-000000000001')
        ->and($owned->validated('division_id'))->toBe('60000000-0000-4000-8000-000000000001');
});

it('does not grant company-wide scope from a global user permission without the selected corporate context', function () {
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
    expect($method->invoke($controller, $request))->toBe('employee');
});

it('does not grant pricing visibility from global permissions without the selected corporate context', function () {
    $controller = app(CorporateBookingController::class);
    $method = new ReflectionMethod($controller, 'canViewPayments');
    $request = Request::create('/api/corporate/bookings/booking-a', 'GET');

    $finance = Mockery::mock();
    $finance->shouldReceive('can')->with('view_payments')->andReturnTrue();
    $request->setUserResolver(fn () => $finance);
    $request->attributes->set('corporate_employee', (object) ['corporate' => (object) [
        'coordinator_can_view_payments' => false,
    ]]);
    expect($method->invoke($controller, $request))->toBeFalse();

    $coordinator = Mockery::mock();
    $coordinator->shouldReceive('can')->with('view_payments')->andReturnFalse();
    $coordinator->shouldReceive('can')->with('create_bookings_for_others')->andReturnTrue();
    $request->setUserResolver(fn () => $coordinator);
    $request->attributes->set('corporate_employee', (object) ['corporate' => (object) [
        'coordinator_can_view_payments' => true,
    ]]);
    expect($method->invoke($controller, $request))->toBeFalse();

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

it('applies payment visibility when transforming corporate booking item lists', function () {
    $item = (new ReflectionClass(BookingItem::class))->newInstanceWithoutConstructor();
    $item->setRawAttributes([
        'id' => 'item-list',
        'status' => 'confirmed',
        'total_price' => 5000,
        'currency' => 'LKR',
    ]);
    foreach (['booking', 'serviceType', 'vehicleGroup', 'vehicle', 'driver'] as $relation) {
        $item->setRelation($relation, null);
    }

    $service = app(CorporateBookingService::class);
    $method = new ReflectionMethod($service, 'transformBookingItemPaginator');
    $restricted = new \Illuminate\Pagination\LengthAwarePaginator(collect([$item]), 1, 25);
    $visible = new \Illuminate\Pagination\LengthAwarePaginator(collect([$item]), 1, 25);

    $method->invoke($service, $restricted, ['can_view_payments' => false]);
    $method->invoke($service, $visible, ['can_view_payments' => true]);

    expect($restricted->items()[0])->not->toHaveKeys(['total_cost', 'currency', 'payment_status'])
        ->and($visible->items()[0])->toHaveKeys(['total_cost', 'currency']);
});
