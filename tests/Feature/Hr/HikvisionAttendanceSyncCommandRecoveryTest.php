<?php

use App\Models\Company;
use App\Models\Hr\Attendance\AttendanceDevice;
use App\Services\Hr\Attendance\DirectAttendanceSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('catches up incremental polling from the last successful sync with the configured overlap', function () {
    $company = Company::create(['name' => 'Hikvision recovery company', 'is_active' => true]);
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
        'integration_mode' => 'direct_isapi',
        'last_sync_at' => '2026-10-01 12:00:00',
    ]);
    $window = null;
    $sync = Mockery::mock(DirectAttendanceSyncService::class);
    $sync->shouldReceive('sync')->once()->withArgs(function ($actualDevice, $from, $to, $type) use (&$window, $device) {
        $window = [$actualDevice->id, $from, $to, $type];
        return true;
    })->andReturn(['counts' => ['read' => 0, 'created' => 0, 'duplicate' => 0, 'quarantined' => 0]]);
    app()->instance(DirectAttendanceSyncService::class, $sync);

    expect(Artisan::call('hr:hikvision-sync', ['--lookback-minutes' => 15]))->toBe(0)
        ->and($window[0])->toBe($device->id)
        ->and($window[1]->equalTo(CarbonImmutable::parse('2026-10-01 11:45:00')))->toBeTrue()
        ->and($window[3])->toBe('incremental');
});

it('keeps explicit multi-day reconciliation windows independent of the incremental watermark', function () {
    $company = Company::create(['name' => 'Hikvision reconciliation company', 'is_active' => true]);
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
        'integration_mode' => 'direct_isapi',
        'last_sync_at' => '2026-10-01 12:00:00',
    ]);
    $window = null;
    $sync = Mockery::mock(DirectAttendanceSyncService::class);
    $sync->shouldReceive('sync')->once()->withArgs(function ($actualDevice, $from, $to, $type) use (&$window, $device) {
        $window = [$actualDevice->id, $from, $to, $type];
        return true;
    })->andReturn(['counts' => ['read' => 0, 'created' => 0, 'duplicate' => 0, 'quarantined' => 0]]);
    app()->instance(DirectAttendanceSyncService::class, $sync);

    expect(Artisan::call('hr:hikvision-sync', ['--days' => 2]))->toBe(0)
        ->and($window[0])->toBe($device->id)
        ->and($window[1]->equalTo($window[2]->subDays(2)))->toBeTrue()
        ->and($window[3])->toBe('reconciliation');
});
