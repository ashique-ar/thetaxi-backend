<?php

use App\Models\Hr\Attendance\AttendanceConnector;
use App\Models\Hr\Attendance\AttendanceDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
    [$adminUser, $company] = hr_seed_admin_actor();

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
    [$adminUser] = hr_seed_admin_actor();

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
