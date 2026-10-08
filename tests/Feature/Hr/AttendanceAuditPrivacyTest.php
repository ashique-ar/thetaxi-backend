<?php

use App\Models\Company;
use App\Models\Hr\Attendance\AttendanceConnector;
use App\Models\Hr\Attendance\AttendanceDailyResult;
use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Hr\Attendance\AttendanceRawEvent;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('keeps attendance credentials, raw punch data and employee identifiers out of activity logs', function () {
    $actor = User::factory()->create();
    $company = Company::create(['name' => 'Attendance audit privacy company', 'is_active' => true, 'is_default' => true]);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    $connector = AttendanceConnector::factory()->create([
        'company_id' => $company->id,
        'connector_key' => 'private-attendance-connector-key',
        'signing_secret' => 'private-attendance-signing-secret',
        'created_user_id' => $actor->id,
    ]);
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id,
        'connector_id' => $connector->id,
        'serial_number' => 'private-attendance-device-serial',
        'encrypted_configuration' => ['username' => 'private-device-user', 'password' => 'private-device-password'],
        'created_user_id' => $actor->id,
    ]);
    $now = now();
    $requestId = (string) Str::uuid();
    DB::table('hr_attendance_ingestion_requests')->insert([
        'id' => $requestId,
        'connector_id' => $connector->id,
        'device_id' => $device->id,
        'request_id' => 'private-ingestion-request',
        'nonce' => 'private-ingestion-nonce',
        'signed_at' => $now,
        'received_at' => $now,
        'payload_checksum' => str_repeat('a', 64),
        'event_count' => 1,
        'status' => 'accepted',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $event = AttendanceRawEvent::create([
        'company_id' => $company->id,
        'connector_id' => $connector->id,
        'device_id' => $device->id,
        'ingestion_request_id' => $requestId,
        'staff_id' => $staff->id,
        'provider_event_id' => 'private-provider-event-id',
        'provider_person_id' => 'private-provider-person-id',
        'employee_number' => 'private-employee-number',
        'occurred_at' => $now,
        'source_timezone' => 'Asia/Colombo',
        'source_utc_offset_minutes' => 330,
        'event_kind' => 'punch',
        'direction' => 'in',
        'encrypted_raw_payload' => ['private' => 'private-raw-punch-payload'],
        'payload_checksum' => str_repeat('b', 64),
        'mapping_status' => 'mapped',
        'received_at' => $now,
    ]);
    $result = AttendanceDailyResult::create([
        'company_id' => $company->id,
        'staff_id' => $staff->id,
        'work_date' => $now->toDateString(),
        'result_version' => 1,
        'day_status' => 'present',
        'first_in_at' => $now,
        'last_out_at' => $now->copy()->addHours(8),
        'worked_minutes' => 480,
        'late_minutes' => 12,
        'source_kind' => 'device_events',
        'calculated_at' => $now,
        'input_checksum' => str_repeat('c', 64),
        'result_checksum' => str_repeat('d', 64),
        'rule_snapshot' => ['private' => 'private-attendance-rule-snapshot'],
    ]);

    $subjects = [
        AttendanceConnector::class => $connector->id,
        AttendanceDevice::class => $device->id,
        AttendanceRawEvent::class => $event->id,
        AttendanceDailyResult::class => $result->id,
    ];
    $properties = collect($subjects)->map(fn ($id, $type) => DB::table('activity_log')->where('subject_type', $type)->where('subject_id', $id)->value('properties'));
    $audit = $properties->filter()->implode(' ');

    expect($properties->filter())->toHaveCount(4)
        ->and($audit)->toContain('direct_isapi')
        ->and($audit)->toContain('present')
        ->and($audit)->toContain('mapped')
        ->and($audit)->not->toContain('private-attendance-connector-key')
        ->and($audit)->not->toContain('private-attendance-signing-secret')
        ->and($audit)->not->toContain('private-attendance-device-serial')
        ->and($audit)->not->toContain('private-device-user')
        ->and($audit)->not->toContain('private-device-password')
        ->and($audit)->not->toContain((string) $staff->id)
        ->and($audit)->not->toContain((string) $actor->id)
        ->and($audit)->not->toContain('private-provider-event-id')
        ->and($audit)->not->toContain('private-provider-person-id')
        ->and($audit)->not->toContain('private-employee-number')
        ->and($audit)->not->toContain('private-raw-punch-payload')
        ->and($audit)->not->toContain('private-attendance-rule-snapshot');

    actingAs($actor, 'api');
    $trackedResult = AttendanceDailyResult::create([
        'company_id' => $company->id,
        'staff_id' => $staff->id,
        'work_date' => $now->toDateString(),
        'result_version' => 2,
        'supersedes_id' => $result->id,
        'day_status' => 'present',
        'source_kind' => 'device_events',
        'calculated_at' => $now,
        'calculated_by' => $actor->id,
        'input_checksum' => str_repeat('e', 64),
        'result_checksum' => str_repeat('f', 64),
        'rule_snapshot' => [],
    ]);
    expect(DB::table('hr_attendance_daily_results')->where('id', $trackedResult->id)->value('created_user_id'))
        ->toBe($actor->id)
        ->and(DB::table('hr_attendance_daily_results')->where('id', $trackedResult->id)->value('calculated_by'))->toBe($actor->id)
        ->and($trackedResult->toArray())->not->toHaveKey('calculated_by')
        ->and($trackedResult->toArray())->not->toHaveKey('created_user_id')
        ->and($trackedResult->toArray())->not->toHaveKey('updated_user_id');

    expect(fn () => $trackedResult->update(['day_status' => 'absent']))
        ->toThrow(LogicException::class, 'Attendance result versions are immutable.');
    expect(DB::table('hr_attendance_daily_results')->where('id', $trackedResult->id)->value('day_status'))->toBe('present');
    expect(fn () => $result->delete())->toThrow(LogicException::class, 'Attendance result versions cannot be deleted.');
});
