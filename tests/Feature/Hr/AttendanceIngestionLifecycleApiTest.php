<?php

use App\Models\Company;
use App\Models\Hr\Attendance\AttendanceConnector;
use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function attendanceIngestionEvent(string $eventId, string $personId, ?string $deviceSerial = null): array
{
    return [
        'provider_event_id' => $eventId,
        'device_serial' => $deviceSerial ?? 'HK-INGEST-01',
        'provider_person_id' => $personId,
        'employee_number' => $personId,
        'occurred_at' => now('Asia/Colombo')->toIso8601String(),
        'source_timezone' => 'Asia/Colombo',
        'source_utc_offset_minutes' => 330,
        'event_kind' => 'punch',
        'direction' => 'in',
        'authentication_method' => 'face',
        'verification_result' => 'accepted',
    ];
}

it('accepts signed attendance events, safely replays requests, and deduplicates provider events', function () {
    config(['hr.features.attendance_ingestion' => true]);
    $company = Company::create(['name' => 'Ingestion lifecycle company', 'is_active' => true]);
    $admin = User::factory()->create();
    $connector = AttendanceConnector::factory()->create([
        'company_id' => $company->id,
        'created_user_id' => $admin->id,
    ]);
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id,
        'connector_id' => $connector->id,
        'serial_number' => 'HK-INGEST-01',
        'created_user_id' => $admin->id,
    ]);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    DB::table('hr_attendance_person_mappings')->insert([
        'id' => (string) Str::uuid(),
        'company_id' => $company->id,
        'staff_id' => $staff->id,
        'device_id' => $device->id,
        'provider_person_id' => 'HK-PERSON-17',
        'employee_number_snapshot' => 'EMP-17',
        'enrollment_status' => 'verified',
        'effective_from' => '2026-01-01',
        'created_by' => $admin->id,
        'verified_by' => $admin->id,
        'last_verified_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $event = attendanceIngestionEvent('HK-EVENT-17', 'HK-PERSON-17');
    $body = json_encode(['events' => [$event]], JSON_THROW_ON_ERROR);
    $send = function (string $requestId, string $nonce) use ($connector, $body) {
        $signedAt = now()->toIso8601String();
        $signature = hash_hmac('sha256', $signedAt.'.'.$nonce.'.'.$body, $connector->signing_secret);

        return $this->call('POST', '/api/hr/attendance/ingest', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CONNECTOR_KEY' => $connector->connector_key,
            'HTTP_X_REQUEST_ID' => $requestId,
            'HTTP_X_NONCE' => $nonce,
            'HTTP_X_SIGNED_AT' => $signedAt,
            'HTTP_X_SIGNATURE' => $signature,
        ], $body);
    };

    $first = $send('request-'.Str::uuid(), 'nonce-'.Str::uuid());
    expect($first->status())->toBe(202, $first->getContent());
    $first->assertJsonPath('data.counts.created', 1)->assertJsonPath('data.counts.duplicate', 0);
    $rawEvent = DB::table('hr_attendance_raw_events')->where('provider_event_id', 'HK-EVENT-17')->first();
    expect($rawEvent->company_id)->toBe($company->id)
        ->and($rawEvent->device_id)->toBe($device->id)
        ->and($rawEvent->staff_id)->toBe($staff->id)
        ->and($rawEvent->mapping_status)->toBe('mapped')
        ->and($rawEvent->encrypted_raw_payload)->not->toContain('HK-PERSON-17');

    $requestId = $first->json('data.request_id');
    $replay = $send($requestId, 'nonce-'.Str::uuid());
    expect($replay->status())->toBe(202, $replay->getContent());
    $replay->assertJsonPath('data.idempotent_replay', true);

    $duplicateEvent = $send('request-'.Str::uuid(), 'nonce-'.Str::uuid());
    expect($duplicateEvent->status())->toBe(202, $duplicateEvent->getContent());
    $duplicateEvent->assertJsonPath('data.counts.duplicate', 1);
    expect(DB::table('hr_attendance_raw_events')->where('connector_id', $connector->id)->where('provider_event_id', 'HK-EVENT-17')->count())->toBe(1);
});

it('quarantines signed events whose provider identity has no approved mapping', function () {
    config(['hr.features.attendance_ingestion' => true]);
    $company = Company::create(['name' => 'Attendance quarantine company', 'is_active' => true]);
    $admin = User::factory()->create();
    $connector = AttendanceConnector::factory()->create([
        'company_id' => $company->id,
        'created_user_id' => $admin->id,
    ]);
    AttendanceDevice::factory()->create([
        'company_id' => $company->id,
        'connector_id' => $connector->id,
        'serial_number' => 'HK-INGEST-01',
        'created_user_id' => $admin->id,
    ]);
    $event = attendanceIngestionEvent('HK-UNMAPPED-18', 'UNREVIEWED-PERSON-18');
    $body = json_encode(['events' => [$event]], JSON_THROW_ON_ERROR);
    $signedAt = now()->toIso8601String();
    $nonce = 'nonce-'.Str::uuid();
    $response = $this->call('POST', '/api/hr/attendance/ingest', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_CONNECTOR_KEY' => $connector->connector_key,
        'HTTP_X_REQUEST_ID' => 'request-'.Str::uuid(),
        'HTTP_X_NONCE' => $nonce,
        'HTTP_X_SIGNED_AT' => $signedAt,
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', $signedAt.'.'.$nonce.'.'.$body, $connector->signing_secret),
    ], $body);

    expect($response->status())->toBe(202, $response->getContent());
    $response
        ->assertJsonPath('data.counts.created', 0)
        ->assertJsonPath('data.counts.quarantined', 1);
    $rawEvent = DB::table('hr_attendance_raw_events')->where('provider_event_id', 'HK-UNMAPPED-18')->first();
    expect($rawEvent->staff_id)->toBeNull()
        ->and($rawEvent->mapping_status)->toBe('quarantined')
        ->and(DB::table('hr_attendance_quarantine_items')->where('raw_event_id', $rawEvent->id)->value('reason_code'))->toBe('person_unmapped');
});
