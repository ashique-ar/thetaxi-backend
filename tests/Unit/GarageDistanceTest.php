<?php

use App\Models\Company;
use App\Services\BookingFlowService;
use App\Services\GoogleMapsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('companies', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->decimal('latitude', 10, 6)->nullable();
        $table->decimal('longitude', 10, 6)->nullable();
        $table->boolean('is_active')->default(true);
        $table->uuid('created_user_id')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    (require database_path('migrations/2026_11_09_000002_add_garage_flag_to_companies.php'))->up();
});

afterEach(function () {
    Schema::dropIfExists('companies');
});

function garageRoute(bool $enabled): array
{
    $reflection = new ReflectionClass(BookingFlowService::class);
    $service = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('includeGarageDistanceForPricing')->setValue($service, $enabled);
    return $reflection->getMethod('calculateRouteDistances')->invoke($service,
        ['latitude' => 7, 'longitude' => 80], ['latitude' => 8, 'longitude' => 81]);
}

it('routes the journey and both directed garage legs', function () {
    DB::table('companies')->insert(['id' => 'garage', 'name' => 'Garage', 'latitude' => 6, 'longitude' => 79, 'is_active' => true, 'is_garage' => true]);
    $maps = Mockery::mock(GoogleMapsService::class);
    $maps->shouldReceive('distanceAndDuration')->once()->with(['latitude' => 7, 'longitude' => 80], ['latitude' => 8, 'longitude' => 81])->andReturn(['distance_km' => 20, 'duration_seconds' => 200]);
    $maps->shouldReceive('distanceAndDuration')->once()->with(['latitude' => 6.0, 'longitude' => 79.0], ['latitude' => 7, 'longitude' => 80])->andReturn(['distance_km' => 5, 'duration_seconds' => 50]);
    $maps->shouldReceive('distanceAndDuration')->once()->with(['latitude' => 8, 'longitude' => 81], ['latitude' => 6.0, 'longitude' => 79.0])->andReturn(['distance_km' => 8, 'duration_seconds' => 80]);
    app()->instance(GoogleMapsService::class, $maps);
    $result = garageRoute(true);
    expect($result['pickup_distance'])->toBe(5.0)->and($result['delivery_distance'])->toBe(8.0)
        ->and($result['journey_distance'])->toBe(20.0)->and($result['total_distance'])->toBe(33.0)
        ->and($result['total_duration_seconds'])->toBe(330)->and($result['garage_distance_failed'])->toBeFalse();
});

it('does not invent garage distances when none is configured and skips them when disabled', function () {
    $maps = Mockery::mock(GoogleMapsService::class);
    $maps->shouldReceive('distanceAndDuration')->twice()->andReturn(['distance_km' => 20, 'duration_seconds' => 200]);
    app()->instance(GoogleMapsService::class, $maps);
    $missing = garageRoute(true);
    $disabled = garageRoute(false);
    expect($missing['garage_distance_failed'])->toBeTrue()->and($missing['total_distance'])->toBeNull()
        ->and($disabled['garage_distance_failed'])->toBeFalse()->and($disabled['total_distance'])->toBe(20.0);
});

it('replaces the selected garage and rejects an inactive or unmapped garage', function () {
    $controller = new \App\Http\Controllers\Api\CompanyController();
    $user = (object) ['id' => 'user'];
    $create = function (array $data) use ($controller, $user) {
        $request = Mockery::mock(\App\Http\Requests\Company\CreateCompanyRequest::class);
        $request->shouldReceive('validated')->once()->andReturn($data);
        $request->shouldReceive('user')->once()->andReturn($user);
        return Company::withoutEvents(fn () => $controller->store($request));
    };
    $create(['name' => 'First', 'latitude' => 6, 'longitude' => 79, 'is_active' => true, 'is_garage' => true]);
    $first = DB::table('companies')->where('is_garage', true)->value('id');
    $create(['name' => 'Second', 'latitude' => 7, 'longitude' => 80, 'is_active' => true, 'is_garage' => true]);
    $second = DB::table('companies')->where('is_garage', true)->value('id');
    expect($second)->not->toBe($first)->and(DB::table('companies')->where('id', $second)->value('name'))->toBe('Second');
    expect(fn () => $create(['name' => 'Invalid', 'is_active' => true, 'is_garage' => true]))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(DB::table('companies')->where('is_garage', true)->value('id'))->toBe($second);
});

it('marks garage routing failures as unavailable instead of zero', function () {
    DB::table('companies')->insert(['id' => 'garage', 'name' => 'Garage', 'latitude' => 6, 'longitude' => 79, 'is_active' => true, 'is_garage' => true]);
    $maps = Mockery::mock(GoogleMapsService::class);
    $maps->shouldReceive('distanceAndDuration')->once()->andReturn(['distance_km' => 20, 'duration_seconds' => 200]);
    $maps->shouldReceive('distanceAndDuration')->twice()->andThrow(new RuntimeException('Route unavailable'));
    app()->instance(GoogleMapsService::class, $maps);
    $result = garageRoute(true);
    expect($result['garage_distance_failed'])->toBeTrue()->and($result['pickup_distance'])->toBeNull()
        ->and($result['delivery_distance'])->toBeNull()->and($result['total_distance'])->toBeNull();
});

it('enforces one garage at the database level', function () {
    DB::table('companies')->insert(['id' => 'one', 'name' => 'One', 'is_garage' => true]);
    expect(fn () => DB::table('companies')->insert(['id' => 'two', 'name' => 'Two', 'is_garage' => true]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('preserves a garage selected using the temporary table', function () {
    $migration = require database_path('migrations/2026_11_09_000002_add_garage_flag_to_companies.php');
    $migration->down();
    DB::table('companies')->insert(['id' => 'garage', 'name' => 'Garage']);
    Schema::create('garage_location', function (Blueprint $table) {
        $table->integer('id')->primary();
        $table->uuid('company_id')->nullable();
    });
    DB::table('garage_location')->insert(['id' => 1, 'company_id' => 'garage']);
    $migration->up();
    expect((bool) DB::table('companies')->where('id', 'garage')->value('is_garage'))->toBeTrue()
        ->and(Schema::hasTable('garage_location'))->toBeFalse();
});

it('supplies garage legs to day-priced self-drive calculation inputs', function () {
    DB::table('companies')->insert(['id' => 'garage', 'name' => 'Garage', 'latitude' => 6, 'longitude' => 79, 'is_active' => true, 'is_garage' => true]);
    $serviceType = new \App\Models\Service\ServiceType(['code' => 'self_drive', 'pricing_mode' => 'day', 'minimum_km' => 0]);
    $serviceType->id = '10000000-0000-4000-8000-000000000001';
    request()->attributes->set('booking_flow.calculation_service_types', ['id:'.$serviceType->id => $serviceType]);
    $service = Mockery::mock(BookingFlowService::class)->makePartial();
    $service->shouldReceive('getDynamicCalculationRequirements')->once()->andReturn(['trip_mode' => 'fixed_route', 'disable_distance_estimate' => false]);
    (new ReflectionProperty(BookingFlowService::class, 'includeGarageDistanceForPricing'))->setValue($service, true);
    $maps = Mockery::mock(GoogleMapsService::class);
    $maps->shouldReceive('distanceAndDuration')->once()->with(['latitude' => 6.0, 'longitude' => 79.0], ['latitude' => 7, 'longitude' => 80])->andReturn(['distance_km' => 5, 'duration_seconds' => 50]);
    $maps->shouldReceive('distanceAndDuration')->once()->with(['latitude' => 8, 'longitude' => 81], ['latitude' => 6.0, 'longitude' => 79.0])->andReturn(['distance_km' => 8, 'duration_seconds' => 80]);
    app()->instance(GoogleMapsService::class, $maps);
    $inputs = (new ReflectionMethod(BookingFlowService::class, 'prepareCalculationInputs'))->invoke($service, [
        'service_type_id' => $serviceType->id,
        'pickup_location' => ['latitude' => 7, 'longitude' => 80],
        'dropoff_location' => ['latitude' => 8, 'longitude' => 81],
        'is_self_driven' => true,
    ]);
    expect($inputs['pickup_distance'])->toBe(5.0)->and($inputs['delivery_distance'])->toBe(8.0)
        ->and($inputs['total_distance'])->toBe(13.0)->and($inputs)->not->toHaveKey('journey_distance');
});
