<?php

use App\Models\Company;
use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Hr\Attendance\AttendanceRawEvent;
use App\Services\Hr\Attendance\AttendanceEventEvidence;
use App\Services\Hr\Attendance\DirectAttendanceSyncService;
use App\Services\Hr\Attendance\HikvisionIsapiAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('counts matching direct events as duplicates and rejects changed evidence for the same event ID', function () {
    config(['hr.features.attendance_ingestion' => true]);
    $company = Company::create(['name' => 'Direct attendance company', 'is_active' => true]);
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id,
        'provider' => 'hikvision',
        'integration_mode' => 'direct_isapi',
        'serial_number' => 'DIRECT-SYNC-1',
    ]);
    $now = now();
    $event = [
        'provider_event_id' => 'direct-event-1',
        'device_serial' => $device->serial_number,
        'provider_person_id' => 'person-1',
        'employee_number' => 'employee-1',
        'occurred_at' => $now->toIso8601String(),
        'source_timezone' => 'Asia/Colombo',
        'source_utc_offset_minutes' => 330,
        'event_kind' => 'punch',
        'direction' => 'in',
        'authentication_method' => 'card',
        'verification_result' => 'success',
    ];
    $requestId = (string) Str::uuid();
    DB::table('hr_attendance_ingestion_requests')->insert([
        'id' => $requestId,
        'device_id' => $device->id,
        'request_id' => 'direct:'.$device->id.':'.$event['provider_event_id'],
        'nonce' => 'sync:prior:'.$event['provider_event_id'],
        'signed_at' => $now,
        'received_at' => $now,
        'payload_checksum' => hash('sha256', json_encode($event, JSON_UNESCAPED_SLASHES)),
        'event_count' => 1,
        'status' => 'accepted',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    AttendanceRawEvent::create([
        'company_id' => $company->id,
        'device_id' => $device->id,
        'ingestion_request_id' => $requestId,
        'provider_event_id' => $event['provider_event_id'],
        'provider_person_id' => $event['provider_person_id'],
        'employee_number' => $event['employee_number'],
        'occurred_at' => $event['occurred_at'],
        'source_timezone' => $event['source_timezone'],
        'source_utc_offset_minutes' => $event['source_utc_offset_minutes'],
        'event_kind' => $event['event_kind'],
        'direction' => $event['direction'],
        'authentication_method' => $event['authentication_method'],
        'verification_result' => $event['verification_result'],
        'encrypted_raw_payload' => AttendanceEventEvidence::minimalPayload($event),
        'payload_checksum' => hash('sha256', json_encode($event, JSON_UNESCAPED_SLASHES)),
        'mapping_status' => 'quarantined',
        'received_at' => $now,
    ]);

    $changedEvent = array_replace($event, ['direction' => 'out']);
    $adapter = Mockery::mock(HikvisionIsapiAdapter::class);
    $adapter->shouldReceive('attendanceEvents')->twice()->andReturn(
        ['events' => [$event], 'next_position' => 1, 'has_more' => false, 'total_matches' => 1],
        ['events' => [$changedEvent], 'next_position' => 1, 'has_more' => false, 'total_matches' => 1],
    );
    app()->instance(HikvisionIsapiAdapter::class, $adapter);
    $service = app(DirectAttendanceSyncService::class);
    $windowStart = CarbonImmutable::parse($now)->subHour();
    $windowEnd = CarbonImmutable::parse($now)->addHour();

    $result = $service->sync($device, $windowStart, $windowEnd);
    expect($result['counts']['duplicate'])->toBe(1)
        ->and(DB::table('hr_attendance_raw_events')->where('device_id', $device->id)->count())->toBe(1);

    expect(fn () => $service->sync($device, $windowStart, $windowEnd))
        ->toThrow(HttpException::class, 'Provider event ID was reused with different attendance evidence.');
    expect(DB::table('hr_attendance_sync_runs')->where('id', '!=', $result['run_id'])->value('status'))->toBe('failed');
});

it('rejects direct provider events with contradictory timezone offset evidence', function () {
    config(['hr.features.attendance_ingestion' => true]);
    $company = Company::create(['name' => 'Direct attendance timezone company', 'is_active' => true]);
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id,
        'provider' => 'hikvision',
        'integration_mode' => 'direct_isapi',
        'serial_number' => 'DIRECT-TIMEZONE-1',
    ]);
    $event = [
        'provider_event_id' => 'direct-event-timezone-1',
        'device_serial' => $device->serial_number,
        'provider_person_id' => 'person-timezone-1',
        'employee_number' => 'employee-timezone-1',
        'occurred_at' => '2026-10-09T08:00:00+05:30',
        'source_timezone' => 'Asia/Colombo',
        'source_utc_offset_minutes' => 300,
        'event_kind' => 'punch',
        'direction' => 'in',
        'authentication_method' => 'card',
        'verification_result' => 'success',
    ];
    $adapter = Mockery::mock(HikvisionIsapiAdapter::class);
    $adapter->shouldReceive('attendanceEvents')->once()->andReturn([
        'events' => [$event], 'next_position' => 1, 'has_more' => false, 'total_matches' => 1,
    ]);
    app()->instance(HikvisionIsapiAdapter::class, $adapter);

    expect(fn () => app(DirectAttendanceSyncService::class)->sync(
        $device,
        CarbonImmutable::parse('2026-10-09T07:00:00+05:30'),
        CarbonImmutable::parse('2026-10-09T09:00:00+05:30'),
    ))->toThrow(HttpException::class, 'Attendance event timezone and UTC offset evidence do not agree.');
    expect(DB::table('hr_attendance_raw_events')->where('provider_event_id', $event['provider_event_id'])->exists())->toBeFalse()
        ->and(DB::table('hr_attendance_sync_runs')->value('status'))->toBe('failed');
});
