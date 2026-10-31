<?php

use App\Models\Hr\Attendance\AttendanceConnector;
use App\Models\Hr\Attendance\AttendanceDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('lists installation-wide attendance devices without exposing tenant metadata', function () {
    [$admin] = hr_seed_admin_actor([], true);
    $connector = AttendanceConnector::factory()->create(['name' => 'Depot Connector']);
    $device = AttendanceDevice::factory()->create([
        'connector_id' => $connector->id,
        'serial_number' => 'FACTORY-SN-0001',
        'site_code' => 'DEPOT-A',
    ]);

    $response = actingAs($admin, 'api')->getJson('/api/hr/attendance/devices')->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $device->id);

    expect($row)->not->toBeNull()
        ->and($row['serial_number'])->toBe('FACTORY-SN-0001')
        ->and($row['site_code'])->toBe('DEPOT-A')
        ->and($row)->not->toHaveKey('company_id')
        ->and($row)->not->toHaveKey('encrypted_configuration')
        ->and($row)->not->toHaveKey('created_user_id')
        ->and($row)->not->toHaveKey('updated_user_id');
});

it('returns the same not-found response for deleted and missing device UUIDs', function () {
    [$admin] = hr_seed_admin_actor([], true);
    $deleted = AttendanceDevice::factory()->create();
    DB::table('hr_attendance_devices')->where('id', $deleted->id)->update(['deleted_at' => now()]);
    $missingId = (string) Str::uuid();
    config(['hr.features.attendance_ingestion' => true]);

    foreach ([$deleted->id, $missingId] as $deviceId) {
        actingAs($admin, 'api')->getJson('/api/hr/attendance/devices/'.$deviceId.'/people')->assertNotFound();
        actingAs($admin, 'api')->putJson('/api/hr/attendance/devices/'.$deviceId, [])->assertNotFound();
        actingAs($admin, 'api')->deleteJson('/api/hr/attendance/devices/'.$deviceId)->assertNotFound();
        actingAs($admin, 'api')->postJson('/api/hr/attendance/devices/'.$deviceId.'/probe')->assertNotFound();
        actingAs($admin, 'api')->postJson('/api/hr/attendance/devices/'.$deviceId.'/sync')->assertNotFound();
    }
});

it('accepts installation-wide connector and organization-unit references', function () {
    [$admin] = hr_seed_admin_actor([], true);
    $connector = AttendanceConnector::factory()->create();
    $unitId = (string) Str::uuid();
    DB::table('hr_organization_units')->insert([
        'id' => $unitId, 'unit_type' => 'department', 'code' => 'OPS', 'name' => 'Operations',
        'effective_from' => '2026-01-01', 'created_user_id' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    config(['hr.features.attendance_ingestion' => true]);
    $payload = [
        'connector_id' => $connector->id,
        'organization_unit_id' => $unitId,
        'provider' => 'hikvision',
        'integration_mode' => 'approved_csv',
        'serial_number' => 'INSTALLATION-SERIAL',
        'site_code' => 'DEPOT-A',
        'timezone' => 'Asia/Colombo',
    ];

    $created = actingAs($admin, 'api')->postJson('/api/hr/attendance/devices', $payload)
        ->assertCreated()->assertJsonMissingPath('data.company_id');
    $deviceId = $created->json('data.id');

    actingAs($admin, 'api')->putJson('/api/hr/attendance/devices/'.$deviceId, [
        ...$payload,
        'status' => 'active',
    ])->assertOk()->assertJsonMissingPath('data.company_id');
});
