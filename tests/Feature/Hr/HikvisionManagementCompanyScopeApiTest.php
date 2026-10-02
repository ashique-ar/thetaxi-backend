<?php

use App\Models\Company;
use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('rejects a verified terminal mapping whose Staff belongs to another company', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    $foreignCompany = Company::create(['name' => 'Foreign Mapped Staff Company']);
    $foreignStaff = Staff::factory()->create(['company_id' => $foreignCompany->id]);
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id, 'provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'status' => 'active',
    ]);
    DB::table('hr_attendance_person_mappings')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $foreignStaff->id, 'device_id' => $device->id,
        'provider_person_id' => 'FOREIGN-STAFF-PERSON', 'employee_number_snapshot' => 'FOREIGN-STAFF-PERSON', 'enrollment_status' => 'verified',
        'effective_from' => '2026-01-01', 'created_by' => $user->id, 'verified_by' => $user->id,
        'last_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($user, 'api')->getJson("/api/hr/attendance/devices/{$device->id}/people/FOREIGN-STAFF-PERSON/credentials")
        ->assertNotFound();
});

it('scopes terminal options, access groups, and maintenance commands to an explicitly selected company', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    $otherCompany = Company::create(['name' => 'Second Hikvision Company']);
    $unassignedCompany = Company::create(['name' => 'Unassigned Hikvision Company']);
    Staff::factory()->create(['user_id' => $user->id, 'company_id' => $otherCompany->id]);
    $device = AttendanceDevice::factory()->create(['company_id' => $company->id]);
    $otherDevice = AttendanceDevice::factory()->create(['company_id' => $otherCompany->id]);

    foreach ([[$company->id, $device->id], [$otherCompany->id, $otherDevice->id]] as [$companyId, $deviceId]) {
        DB::table('hr_attendance_access_groups')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'device_id' => $deviceId,
            'code' => 'STAFF-ACCESS',
            'name' => 'Staff entry',
            'door_no' => 1,
            'plan_template_no' => 1,
            'effective_from' => '2026-10-01',
            'status' => 'active',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('hr_attendance_device_maintenance_commands')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'device_id' => $deviceId,
            'command_type' => 'reboot',
            'status' => 'pending_approval',
            'reason' => 'Verified maintenance window.',
            'typed_confirmation' => 'SITE',
            'idempotency_key' => 'reboot-'.$companyId,
            'requested_by' => $user->id,
            'requested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    actingAs($user, 'api')->getJson('/api/hr/attendance/device-options?company_id='.$otherCompany->id)
        ->assertOk()->assertJsonPath('data.data.0.value', $otherDevice->id);
    actingAs($user, 'api')->getJson('/api/hr/attendance/device-options')
        ->assertForbidden();
    actingAs($user, 'api')->getJson('/api/hr/attendance/access-groups?company_id='.$otherCompany->id.'&device_id='.$otherDevice->id)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.device_id', $otherDevice->id);
    actingAs($user, 'api')->getJson('/api/hr/attendance/access-groups?company_id='.$unassignedCompany->id)
        ->assertForbidden();
    actingAs($user, 'api')->getJson('/api/hr/attendance/maintenance-commands?company_id='.$otherCompany->id)
        ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.company_id', $otherCompany->id);
    actingAs($user, 'api')->getJson('/api/hr/attendance/maintenance-commands')
        ->assertUnprocessable();

    config(['hr.features.attendance_ingestion' => true]);
    actingAs($user, 'api')->postJson('/api/hr/attendance/devices/'.$otherDevice->id.'/sync', ['days' => 2])
        ->assertUnprocessable();
    actingAs($user, 'api')->postJson('/api/hr/attendance/devices/'.$otherDevice->id.'/probe')
        ->assertUnprocessable();
    actingAs($user, 'api')->postJson('/api/hr/attendance/devices/'.$otherDevice->id.'/sync', [
        'company_id' => $company->id,
        'days' => 2,
    ])->assertNotFound();
    actingAs($user, 'api')->postJson('/api/hr/attendance/devices/'.$otherDevice->id.'/probe', [
        'company_id' => $company->id,
    ])->assertNotFound();

    actingAs($user, 'api')->postJson('/api/hr/attendance/access-groups', [
        'device_id' => $otherDevice->id,
        'code' => 'VISITOR-ACCESS',
        'name' => 'Visitor entry',
        'door_no' => 2,
        'plan_template_no' => 2,
        'effective_from' => '2026-10-01',
    ])->assertCreated()->assertJsonPath('data.company_id', $otherCompany->id);

    config(['hr.features.hikvision_maintenance_commands' => true]);
    $otherDevice->forceFill(['capabilities' => [
        'maintenance' => ['reboot' => true],
        'last_identity_probe_at' => now()->toIso8601String(),
    ]])->save();
    $payload = [
        'reason' => 'Approved maintenance window.',
        'typed_confirmation' => $otherDevice->site_code,
        'idempotency_key' => 'selected-company-reboot',
    ];
    $created = actingAs($user, 'api')->postJson('/api/hr/attendance/devices/'.$otherDevice->id.'/reboot-requests', $payload)
        ->assertCreated()->assertJsonPath('data.company_id', $otherCompany->id)->json('data');
    actingAs($user, 'api')->postJson('/api/hr/attendance/devices/'.$otherDevice->id.'/reboot-requests', $payload)
        ->assertOk()->assertJsonPath('data.id', $created['id']);
    actingAs($user, 'api')->postJson('/api/hr/attendance/devices/'.$otherDevice->id.'/reboot-requests', array_replace($payload, ['reason' => 'Different request']))
        ->assertConflict();

    $device->forceFill(['capabilities' => [
        'maintenance' => ['reboot' => true],
        'last_identity_probe_at' => now()->toIso8601String(),
    ]])->save();
    actingAs($user, 'api')->postJson('/api/hr/attendance/devices/'.$device->id.'/reboot-requests', [
        'reason' => 'Verified maintenance window.',
        'typed_confirmation' => $device->site_code,
        'idempotency_key' => 'reboot-'.$otherCompany->id,
    ])->assertConflict();
});

it('lets device and maintenance viewers fetch authorized company options', function () {
    [, $company] = hr_seed_admin_actor();
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('hr.attendance.maintenance.execute');
    Staff::factory()->create(['user_id' => $viewer->id, 'company_id' => $company->id]);

    actingAs($viewer, 'api')->getJson('/api/hr/attendance/company-options')
        ->assertOk()->assertJsonPath('data.0.value', $company->id);
});

it('hides Hikvision device UUIDs outside the active Staff company scope', function () {
    [$user] = hr_seed_admin_actor([], true);
    $unassignedCompany = Company::create(['name' => 'Unassigned Device Company']);
    $foreignDevice = AttendanceDevice::factory()->create(['company_id' => $unassignedCompany->id]);
    $foreignStaff = Staff::factory()->create(['company_id' => $unassignedCompany->id]);
    $alertId = (string) Str::uuid();
    $accessCommandId = (string) Str::uuid();
    $maintenanceCommandId = (string) Str::uuid();
    $mappingId = (string) Str::uuid();
    DB::table('hr_attendance_device_alerts')->insert([
        'id' => $alertId, 'company_id' => $unassignedCompany->id, 'device_id' => $foreignDevice->id,
        'alert_type' => 'offline', 'severity' => 'high', 'status' => 'open', 'message' => 'Device offline',
        'dedupe_key' => hash('sha256', $alertId), 'detected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_attendance_access_commands')->insert([
        'id' => $accessCommandId, 'company_id' => $unassignedCompany->id, 'staff_id' => $foreignStaff->id,
        'device_id' => $foreignDevice->id, 'command_type' => 'remove_access', 'status' => 'dead_letter',
        'request_snapshot' => '{}', 'idempotency_key' => 'foreign-access-retry', 'requested_by' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_attendance_device_maintenance_commands')->insert([
        'id' => $maintenanceCommandId, 'company_id' => $unassignedCompany->id, 'device_id' => $foreignDevice->id,
        'command_type' => 'reboot', 'status' => 'pending_approval', 'reason' => 'Maintenance window',
        'typed_confirmation' => 'SITE', 'idempotency_key' => 'foreign-reboot-approval', 'requested_by' => $user->id,
        'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_attendance_person_mappings')->insert([
        'id' => $mappingId, 'company_id' => $unassignedCompany->id, 'staff_id' => $foreignStaff->id,
        'device_id' => $foreignDevice->id, 'provider_person_id' => 'foreign-person',
        'employee_number_snapshot' => 'FOREIGN-1', 'enrollment_status' => 'pending',
        'effective_from' => '2026-10-01', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($user, 'api')->getJson('/api/hr/attendance/devices/'.$foreignDevice->id.'/diagnostics')->assertNotFound();
    actingAs($user, 'api')->getJson('/api/hr/attendance/devices/'.Str::uuid().'/diagnostics')->assertNotFound();
    actingAs($user, 'api')->postJson('/api/hr/attendance/device-alerts/'.$alertId.'/resolve', ['note' => 'Resolved'])
        ->assertNotFound();
    actingAs($user, 'api')->postJson('/api/hr/attendance/device-alerts/'.Str::uuid().'/resolve', ['note' => 'Resolved'])
        ->assertNotFound();
    actingAs($user, 'api')->postJson('/api/hr/attendance/access-commands/'.$accessCommandId.'/retry', ['reason' => 'Retry'])
        ->assertNotFound();
    actingAs($user, 'api')->postJson('/api/hr/attendance/access-commands/'.Str::uuid().'/retry', ['reason' => 'Retry'])
        ->assertNotFound();
    config(['hr.features.physical_access_commands' => true]);
    actingAs($user, 'api')->postJson('/api/hr/attendance/access-commands/'.$accessCommandId.'/approve', [])
        ->assertNotFound();
    actingAs($user, 'api')->postJson('/api/hr/attendance/access-commands/'.Str::uuid().'/approve', [])
        ->assertNotFound();

    config(['hr.features.hikvision_maintenance_commands' => true]);
    actingAs($user, 'api')->postJson('/api/hr/attendance/maintenance-commands/'.$maintenanceCommandId.'/approve', [])
        ->assertNotFound();
    actingAs($user, 'api')->postJson('/api/hr/attendance/maintenance-commands/'.Str::uuid().'/approve', [])
        ->assertNotFound();

    config(['hr.features.attendance_ingestion' => true]);
    actingAs($user, 'api')->postJson('/api/hr/attendance/person-mappings/'.$mappingId.'/approve', [])
        ->assertNotFound();
    actingAs($user, 'api')->postJson('/api/hr/attendance/person-mappings/'.Str::uuid().'/approve', [])
        ->assertNotFound();

    $localStaff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $mappingPayload = [
        'company_id' => $localStaff->company_id, 'provider_person_id' => 'foreign-person-write',
        'effective_from' => '2026-10-01',
    ];
    actingAs($user, 'api')->postJson('/api/hr/attendance/person-mappings', $mappingPayload + ['staff_id' => $foreignStaff->id])
        ->assertNotFound();
    actingAs($user, 'api')->postJson('/api/hr/attendance/person-mappings', $mappingPayload + ['staff_id' => (string) Str::uuid()])
        ->assertNotFound();
    actingAs($user, 'api')->postJson('/api/hr/attendance/person-mappings', $mappingPayload + [
        'staff_id' => $localStaff->id, 'device_id' => $foreignDevice->id,
    ])->assertNotFound();
    actingAs($user, 'api')->postJson('/api/hr/attendance/person-mappings', $mappingPayload + [
        'staff_id' => $localStaff->id, 'device_id' => (string) Str::uuid(),
    ])->assertNotFound();
});

it('scopes physical-access request references and replays only identical evidence', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    $staff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $foreignCompany = Company::create(['name' => 'Foreign Access Request Company']);
    $foreignStaff = Staff::factory()->create(['company_id' => $foreignCompany->id]);
    $foreignDevice = AttendanceDevice::factory()->create(['company_id' => $foreignCompany->id]);
    $device = AttendanceDevice::factory()->create(['company_id' => $company->id]);
    config(['hr.features.physical_access_commands' => true]);

    $url = '/api/hr/attendance/access-commands';
    $payload = [
        'company_id' => $company->id, 'staff_id' => $foreignStaff->id, 'device_id' => $device->id, 'command_type' => 'revoke',
        'reason' => 'Reviewed departure', 'idempotency_key' => 'access-scope-1',
    ];
    actingAs($user, 'api')->postJson($url, $payload)->assertNotFound();
    actingAs($user, 'api')->postJson($url, array_replace($payload, ['staff_id' => (string) Str::uuid()]))->assertNotFound();
    actingAs($user, 'api')->postJson($url, array_replace($payload, ['staff_id' => $staff->id, 'device_id' => $foreignDevice->id]))->assertNotFound();
    actingAs($user, 'api')->postJson($url, array_replace($payload, ['staff_id' => $staff->id, 'device_id' => (string) Str::uuid()]))->assertNotFound();

    $valid = array_replace($payload, ['staff_id' => $staff->id]);
    $created = actingAs($user, 'api')->postJson($url, $valid)->assertCreated()->json('data');
    actingAs($user, 'api')->postJson($url, $valid)->assertOk()->assertJsonPath('data.id', $created['id']);
    actingAs($user, 'api')->postJson($url, array_replace($valid, ['reason' => 'Changed reason']))->assertConflict();
});

