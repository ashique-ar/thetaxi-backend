<?php

use App\Models\Company;
use App\Models\Hr\Attendance\AttendanceConnector;
use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('offers only event-eligible mappings and resolves without changing the raw event', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $connector = AttendanceConnector::factory()->create(['company_id' => $company->id, 'created_user_id' => $admin->id]);
    $device = AttendanceDevice::factory()->create(['company_id' => $company->id, 'connector_id' => $connector->id, 'created_user_id' => $admin->id]);
    $otherDevice = AttendanceDevice::factory()->create(['company_id' => $company->id, 'connector_id' => $connector->id, 'created_user_id' => $admin->id]);
    $now = now();
    $ingestionId = (string) Str::uuid();
    DB::table('hr_attendance_ingestion_requests')->insert([
        'id' => $ingestionId, 'connector_id' => $connector->id, 'request_id' => 'request-'.Str::uuid(), 'nonce' => 'nonce-'.Str::uuid(),
        'signed_at' => $now, 'received_at' => $now, 'payload_checksum' => str_repeat('a', 64), 'event_count' => 1, 'status' => 'accepted',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $eventId = (string) Str::uuid();
    DB::table('hr_attendance_raw_events')->insert([
        'id' => $eventId, 'company_id' => $company->id, 'connector_id' => $connector->id, 'device_id' => $device->id,
        'ingestion_request_id' => $ingestionId, 'provider_event_id' => 'event-1', 'provider_person_id' => 'PERSON-42',
        'employee_number' => '42', 'occurred_at' => '2026-08-01 08:00:00+05:30', 'source_timezone' => 'Asia/Colombo',
        'source_utc_offset_minutes' => 330, 'event_kind' => 'attendance', 'encrypted_raw_payload' => 'encrypted',
        'payload_checksum' => str_repeat('b', 64), 'mapping_status' => 'quarantined', 'received_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $itemId = (string) Str::uuid();
    DB::table('hr_attendance_quarantine_items')->insert([
        'id' => $itemId, 'company_id' => $company->id, 'raw_event_id' => $eventId, 'reason_code' => 'unmatched_identity',
        'details' => 'Review identity mapping.', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $mapping = function (array $overrides = []) use ($company, $staff, $device, $admin, $now) {
        $id = (string) Str::uuid();
        DB::table('hr_attendance_person_mappings')->insert(array_merge([
            'id' => $id, 'company_id' => $company->id, 'staff_id' => $staff->id, 'device_id' => $device->id,
            'provider_person_id' => 'PERSON-42', 'employee_number_snapshot' => 'EMP-42', 'enrollment_status' => 'verified',
            'effective_from' => '2026-01-01', 'effective_until' => null, 'created_by' => $admin->id, 'verified_by' => $admin->id,
            'last_verified_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ], $overrides));

        return $id;
    };
    $eligibleId = $mapping();
    $mapping(['provider_person_id' => 'OTHER-PERSON']);
    $mapping(['device_id' => $otherDevice->id]);
    $mapping(['enrollment_status' => 'pending']);
    $mapping(['effective_from' => '2026-09-01']);
    $foreign = Company::create(['name' => 'Foreign Attendance Company']);
    $foreignStaff = Staff::factory()->create(['company_id' => $foreign->id]);
    $foreignMappingId = (string) Str::uuid();
    DB::table('hr_attendance_person_mappings')->insert([
        'id' => $foreignMappingId, 'company_id' => $foreign->id, 'staff_id' => $foreignStaff->id, 'device_id' => null,
        'provider_person_id' => 'PERSON-42', 'employee_number_snapshot' => 'FOREIGN', 'enrollment_status' => 'verified',
        'effective_from' => '2026-01-01', 'created_by' => $admin->id, 'verified_by' => $admin->id,
        'last_verified_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $url = "/api/hr/attendance/quarantine/{$itemId}/mapping-options";
    actingAs($admin, 'api')->getJson($url.'?search='.$staff->code.'&per_page=50')->assertOk()
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.value', $eligibleId)
        ->assertJsonPath('data.data.0.status', 'verified');
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$eligibleId)->assertOk()->assertJsonPath('data.data.0.value', $eligibleId);
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$foreignMappingId)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'?per_page=51')->assertUnprocessable();

    $before = DB::table('hr_attendance_raw_events')->where('id', $eventId)->first();
    $invalidId = DB::table('hr_attendance_person_mappings')->where('provider_person_id', 'OTHER-PERSON')->value('id');
    actingAs($admin, 'api')->postJson("/api/hr/attendance/quarantine/{$itemId}/resolve", [
        'person_mapping_id' => $invalidId, 'reason' => 'Should be rejected.',
    ])->assertUnprocessable();
    actingAs($admin, 'api')->postJson("/api/hr/attendance/quarantine/{$itemId}/resolve", [
        'person_mapping_id' => $eligibleId, 'reason' => 'Reviewed against the device record.',
    ])->assertOk();

    $after = DB::table('hr_attendance_raw_events')->where('id', $eventId)->first();
    expect($after->mapping_status)->toBe($before->mapping_status)
        ->and($after->provider_person_id)->toBe($before->provider_person_id)
        ->and(DB::table('hr_attendance_quarantine_items')->where('id', $itemId)->value('resolved_mapping_id'))->toBe($eligibleId);
});
