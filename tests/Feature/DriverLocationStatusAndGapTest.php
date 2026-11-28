<?php

use App\Http\Controllers\Api\Driver\DriverController;
use App\Http\Controllers\Api\Driver\Mobile\HeartbeatController;
use App\Http\Controllers\Api\Driver\Mobile\LocationController;
use App\Models\Driver\Driver;
use App\Models\Driver\DriverSession;
use App\Models\Driver\DriverTrackingIncident;
use App\Models\Driver\RoutePoint;
use App\Models\User;
use App\Services\WebsiteSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function setupDriverLocationGapSchema(): void
{
    if (!Schema::hasTable('drivers')) {
        Schema::create('drivers', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('code')->nullable();
            $table->boolean('is_online')->default(false);
            $table->timestamp('last_active_at')->nullable();
            $table->decimal('current_latitude', 10, 8)->nullable();
            $table->decimal('current_longitude', 11, 8)->nullable();
            $table->boolean('location_enabled')->nullable();
            $table->timestamp('location_status_updated_at')->nullable();
            $table->string('current_device_uuid')->nullable();
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    if (!Schema::hasTable('driver_sessions')) {
        Schema::create('driver_sessions', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('driver_id');
            $table->string('device_uuid')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('start_time')->nullable();
            $table->timestamp('end_time')->nullable();
            $table->decimal('start_latitude', 10, 8)->nullable();
            $table->decimal('start_longitude', 11, 8)->nullable();
            $table->decimal('end_latitude', 10, 8)->nullable();
            $table->decimal('end_longitude', 11, 8)->nullable();
            $table->decimal('total_distance_km', 10, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    if (!Schema::hasTable('route_points')) {
        Schema::create('route_points', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id');
            $table->uuid('assignment_id')->nullable();
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->decimal('speed', 8, 2)->nullable();
            $table->decimal('heading', 8, 2)->nullable();
            $table->decimal('accuracy', 8, 2)->nullable();
            $table->boolean('sensor_stationary')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
        });
    }

    if (!Schema::hasTable('driver_devices')) {
        Schema::create('driver_devices', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('driver_id');
            $table->string('device_uuid')->nullable();
            $table->string('device_name')->nullable();
            $table->string('device_model')->nullable();
            $table->string('device_manufacturer')->nullable();
            $table->string('platform')->nullable();
            $table->string('os_version')->nullable();
            $table->string('app_version')->nullable();
            $table->string('app_build')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_active_at')->nullable();
            $table->string('locale')->nullable();
            $table->string('timezone')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    if (!Schema::hasTable('driver_assignments')) {
        Schema::create('driver_assignments', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('driver_id');
            $table->uuid('booking_id')->nullable();
            $table->uuid('booking_item_id')->nullable();
            $table->string('status')->nullable();
            $table->string('trip_phase')->nullable();
            $table->timestamp('assigned_from')->nullable();
            $table->timestamp('assigned_to')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('trip_started_at')->nullable();
            $table->timestamp('pickup_arrived_at')->nullable();
            $table->timestamp('trip_completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    if (!Schema::hasTable('driver_tracking_incidents')) {
        Schema::create('driver_tracking_incidents', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('incident_id')->unique();
            $table->uuid('driver_id');
            $table->uuid('assignment_id')->nullable();
            $table->uuid('booking_id')->nullable();
            $table->uuid('booking_item_id')->nullable();
            $table->string('state', 20);
            $table->string('cause', 50);
            $table->timestamp('started_at');
            $table->timestamp('reported_at');
            $table->timestamp('recovered_at')->nullable();
            $table->json('first_evidence');
            $table->json('last_evidence');
            $table->timestamps();
            $table->softDeletes();
        });
    }
}

it('records mobile location_enabled from health and heartbeat and exposes location_gaps on movement map', function () {
    setupDriverLocationGapSchema();
    activity()->disableLogging();

    $user = new User(['id' => (string) Str::uuid(), 'first_name' => 'Test', 'last_name' => 'Driver']);
    $driver = Driver::create([
        'user_id' => $user->id,
        'code' => 'DRV-TEST',
        'is_online' => true,
        'last_active_at' => now(),
        'current_latitude' => 6.9271,
        'current_longitude' => 79.8612,
    ]);

    $authService = Mockery::mock(\App\Services\Driver\DriverAuthService::class);
    $authService->shouldReceive('getDriver')->andReturn($driver);

    $locationController = new LocationController(
        Mockery::mock(\App\Services\Driver\LocationService::class),
        $authService,
        new \App\Services\BookingOperationsHealthMonitor(),
    );

    $healthRequest = Request::create('/api/driver/location/health', 'POST', [
        'state' => 'blocked',
        'queue_count' => 0,
        'location_enabled' => false,
    ]);
    $healthRequest->setUserResolver(fn () => $user);

    $healthResponse = $locationController->health($healthRequest);
    expect($healthResponse->getStatusCode())->toBe(200);
    expect($driver->fresh()->location_enabled)->toBeFalse();
    expect($driver->fresh()->location_status_updated_at)->not->toBeNull();

    $heartbeatController = new HeartbeatController(
        new \App\Services\Driver\SessionService(),
        $authService,
        Mockery::mock(\App\Services\Driver\MobileAssignmentService::class)->shouldIgnoreMissing(),
    );

    $heartbeatRequest = Request::create('/api/driver/heartbeat', 'POST', [
        'location_enabled' => true,
    ]);
    $heartbeatRequest->setUserResolver(fn () => $user);

    $heartbeatResponse = $heartbeatController->ping($heartbeatRequest);
    $heartbeatPayload = $heartbeatResponse->getData(true);
    expect($heartbeatResponse->getStatusCode())->toBe(200);
    expect($heartbeatPayload['data']['location_enabled'])->toBeTrue();
    expect($driver->fresh()->location_enabled)->toBeTrue();

    $session = DriverSession::create([
        'driver_id' => $driver->id,
        'status' => 'active',
        'start_time' => now()->subHour(),
        'start_latitude' => 6.9200,
        'start_longitude' => 79.8600,
    ]);

    RoutePoint::create([
        'session_id' => $session->id,
        'latitude' => 6.9210,
        'longitude' => 79.8605,
        'recorded_at' => now()->subMinutes(40),
    ]);

    RoutePoint::create([
        'session_id' => $session->id,
        'latitude' => 6.9271,
        'longitude' => 79.8612,
        'recorded_at' => now()->subMinutes(10),
    ]);

    DriverTrackingIncident::withoutEvents(function () use ($driver) {
        DriverTrackingIncident::create([
            'incident_id' => (string) Str::uuid(),
            'driver_id' => $driver->id,
            'state' => 'recovered',
            'cause' => 'location_source_stalled',
            'started_at' => now()->subMinutes(35),
            'reported_at' => now()->subMinutes(30),
            'recovered_at' => now()->subMinutes(15),
            'first_evidence' => [
                'observed_at' => now()->subMinutes(35)->toIso8601String(),
                'location_enabled' => false,
                'blocked_reason' => 'location_service_disabled',
            ],
            'last_evidence' => [
                'observed_at' => now()->subMinutes(15)->toIso8601String(),
                'location_enabled' => true,
            ],
        ]);
    });

    $settingsService = Mockery::mock(WebsiteSettingsService::class);
    $settingsService->shouldReceive('isDriverMobileAppEnabled')->andReturn(true);

    $driverController = new DriverController(
        Mockery::mock(\App\Services\UserContextService::class),
        Mockery::mock(\App\Services\Driver\NotificationTriggerService::class),
        $settingsService,
    );

    $movementRequest = Request::create('/api/drivers/' . $driver->id . '/movement-map', 'GET', [
        'date' => now()->toDateString(),
        'timezone' => 'UTC',
    ]);

    $movementResponse = $driverController->movementMap($movementRequest, $driver);
    $movementData = $movementResponse->getData(true)['data'];

    expect($movementData['summary']['location_gap_count'])->toBe(1);
    expect($movementData['summary']['location_off_gap_count'])->toBe(1);
    expect($movementData['location_gaps'])->toHaveCount(1);
    expect($movementData['location_gaps'][0]['location_enabled'])->toBeFalse();
    expect($movementData['location_gaps'][0]['start_location']['latitude'])->toBe(6.921);
    expect($movementData['location_gaps'][0]['end_location']['latitude'])->toBe(6.9271);
});
