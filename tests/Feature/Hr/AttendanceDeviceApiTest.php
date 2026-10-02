<?php

use App\Models\Hr\Attendance\AttendanceConnector;
use App\Models\Hr\Attendance\AttendanceDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * Exercises the real attendance-device list endpoint
 * ({@see \App\Http\Controllers\Api\Hr\AttendanceDeviceController::index()},
 * now composed from Concerns/ManagesAttendanceDeviceCrud) end to end against
 * a real (sqlite, in-memory) database, using the new AttendanceDevice /
 * AttendanceConnector factories — replacing the former Hr*ContractTest.php
 * pattern of just grepping controller source text with a test that verifies
 * real behavior.
 */
it('returns an AttendanceDevice created via the factory from the device list endpoint', function () {
    [$adminUser, $company] = hr_seed_admin_actor([], true);

    $connector = AttendanceConnector::factory()->create([
        'company_id' => $company->id,
        'name' => 'Depot A Connector',
    ]);
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id,
        'connector_id' => $connector->id,
        'serial_number' => 'FACTORY-SN-0001',
        'site_code' => 'DEPOT-A',
    ]);

    $response = actingAs($adminUser, 'api')->getJson('/api/hr/attendance/devices');

    $response->assertOk();
    $rows = collect($response->json('data'));
    $row = $rows->firstWhere('id', $device->id);

    expect($row)->not->toBeNull()
        ->and($row['serial_number'])->toBe('FACTORY-SN-0001')
        ->and($row['site_code'])->toBe('DEPOT-A')
        ->and($row['company_id'])->toBe($company->id)
        ->and($row)->not->toHaveKey('encrypted_configuration');
});

it('excludes an AttendanceDevice belonging to a different company from the device list', function () {
    [$adminUser] = hr_seed_admin_actor([], true);

    $otherCompany = \App\Models\Company::create(['name' => 'Other Attendance Co']);
    $otherDevice = AttendanceDevice::factory()->create([
        'company_id' => $otherCompany->id,
        'serial_number' => 'FACTORY-SN-OTHER',
    ]);

    $response = actingAs($adminUser, 'api')->getJson('/api/hr/attendance/devices');

    $response->assertOk();
    $rows = collect($response->json('data'));

    expect($rows->firstWhere('id', $otherDevice->id))->toBeNull();
});

it('returns the same not-found response for foreign and missing device UUIDs', function () {
    [$adminUser, $company] = hr_seed_admin_actor([], true);
    $foreignCompany = \App\Models\Company::create(['name' => 'Foreign Device Company']);
    $foreignDevice = AttendanceDevice::factory()->create(['company_id' => $foreignCompany->id]);
    $missingId = (string) Str::uuid();
    config(['hr.features.attendance_ingestion' => true]);

    foreach ([$foreignDevice->id, $missingId] as $deviceId) {
        actingAs($adminUser, 'api')->getJson('/api/hr/attendance/devices/'.$deviceId.'/people')->assertNotFound();
        actingAs($adminUser, 'api')->putJson('/api/hr/attendance/devices/'.$deviceId, [])->assertNotFound();
        actingAs($adminUser, 'api')->deleteJson('/api/hr/attendance/devices/'.$deviceId)->assertNotFound();
        actingAs($adminUser, 'api')->postJson('/api/hr/attendance/devices/'.$deviceId.'/probe', ['company_id' => $company->id])->assertNotFound();
        actingAs($adminUser, 'api')->postJson('/api/hr/attendance/devices/'.$deviceId.'/sync', ['company_id' => $company->id])->assertNotFound();
    }

    $this->assertDatabaseHas('hr_attendance_devices', ['id' => $foreignDevice->id]);
});

it('hides connector references outside the selected company on device create and update', function () {
    [$adminUser, $company] = hr_seed_admin_actor([], true);
    $foreignCompany = \App\Models\Company::create(['name' => 'Foreign Connector Company']);
    $foreignConnector = AttendanceConnector::factory()->create(['company_id' => $foreignCompany->id]);
    $foreignUnitId = (string) Str::uuid();
    DB::table('hr_organization_units')->insert([
        'id' => $foreignUnitId, 'company_id' => $foreignCompany->id, 'unit_type' => 'department', 'code' => 'FOREIGN',
        'name' => 'Foreign department', 'effective_from' => '2026-01-01', 'created_user_id' => $adminUser->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $device = AttendanceDevice::factory()->create(['company_id' => $company->id]);
    config(['hr.features.attendance_ingestion' => true]);

    $create = [
        'company_id' => $company->id, 'provider' => 'hikvision', 'integration_mode' => 'approved_csv',
        'serial_number' => 'SCOPED-SERIAL', 'site_code' => 'SCOPED-SITE', 'timezone' => 'Asia/Colombo',
    ];
    foreach ([$foreignConnector->id, (string) Str::uuid()] as $connectorId) {
        actingAs($adminUser, 'api')->postJson('/api/hr/attendance/devices', $create + ['connector_id' => $connectorId])->assertNotFound();
        actingAs($adminUser, 'api')->putJson('/api/hr/attendance/devices/'.$device->id, [
            'connector_id' => $connectorId, 'site_code' => $device->site_code, 'timezone' => $device->timezone, 'status' => $device->status,
        ])->assertNotFound();
    }
    foreach ([$foreignUnitId, (string) Str::uuid()] as $unitId) {
        actingAs($adminUser, 'api')->postJson('/api/hr/attendance/devices', $create + ['organization_unit_id' => $unitId])->assertNotFound();
        actingAs($adminUser, 'api')->putJson('/api/hr/attendance/devices/'.$device->id, [
            'organization_unit_id' => $unitId, 'site_code' => $device->site_code, 'timezone' => $device->timezone, 'status' => $device->status,
        ])->assertNotFound();
    }

    $this->assertDatabaseHas('hr_attendance_devices', ['id' => $device->id, 'connector_id' => null]);
});
