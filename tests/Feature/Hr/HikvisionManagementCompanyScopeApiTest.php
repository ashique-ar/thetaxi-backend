<?php

use App\Models\Company;
use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use App\Console\Commands\SyncHikvisionPeople;
use App\Services\Hr\Attendance\AttendanceProviderManager;
use App\Services\Hr\Attendance\HikvisionIsapiAdapter;
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

it('does not return credential history rows whose company disagrees with the authorized device', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    $otherCompany = Company::create(['name' => 'Credential History Foreign Company']);
    $staff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $foreignStaff = Staff::factory()->create(['company_id' => $otherCompany->id]);
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id, 'provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'status' => 'active',
    ]);
    DB::table('hr_attendance_person_mappings')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id, 'device_id' => $device->id,
        'provider_person_id' => 'LOCAL-STAFF-PERSON', 'employee_number_snapshot' => 'LOCAL-STAFF-PERSON', 'enrollment_status' => 'verified',
        'effective_from' => '2026-01-01', 'created_by' => $user->id, 'verified_by' => $user->id,
        'last_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_attendance_credential_events')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $otherCompany->id, 'device_id' => $device->id, 'staff_id' => $foreignStaff->id,
        'provider_person_id' => 'LOCAL-STAFF-PERSON', 'credential_type' => 'card', 'action' => 'set',
        'credential_fingerprint' => str_repeat('a', 64), 'masked_reference' => '****1234', 'status' => 'delivered',
        'reason' => 'Mismatched legacy row', 'idempotency_key' => 'mismatched-credential-history', 'request_checksum' => str_repeat('b', 64),
        'actor_user_id' => $user->id, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $adapter = Mockery::mock(HikvisionIsapiAdapter::class)->makePartial();
    $adapter->shouldReceive('cards')->once()->andReturn([]);
    app()->instance(HikvisionIsapiAdapter::class, $adapter);

    actingAs($user, 'api')->getJson("/api/hr/attendance/devices/{$device->id}/people/LOCAL-STAFF-PERSON/credentials")
        ->assertOk()->assertJsonCount(0, 'data.history');
});

it('scopes terminal options, access groups, and maintenance commands to an explicitly selected company', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    $otherCompany = Company::create(['name' => 'Second Hikvision Company']);
    $unassignedCompany = Company::create(['name' => 'Unassigned Hikvision Company']);
    $otherStaff = Staff::factory()->create(['user_id' => $user->id, 'company_id' => $otherCompany->id]);
    $otherContext = UserContext::create([
        'user_id' => $user->id, 'context_type' => 'staff', 'context_id' => $otherStaff->id,
        'is_active' => true, 'created_user_id' => $user->id,
    ]);
    $otherHeaders = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $otherContext->id];
    $localStaff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $localContext = UserContext::query()->where('user_id', $user->id)->where('context_id', $localStaff->id)->firstOrFail();
    $localHeaders = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $localContext->id];
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

    actingAs($user, 'api')->withHeaders($otherHeaders)->getJson('/api/hr/attendance/device-options?company_id='.$otherCompany->id)
        ->assertOk()->assertJsonPath('data.data.0.value', $otherDevice->id);
    actingAs($user, 'api')->getJson('/api/hr/attendance/device-options')
        ->assertForbidden();
    actingAs($user, 'api')->withHeaders($otherHeaders)->getJson('/api/hr/attendance/access-groups?company_id='.$otherCompany->id.'&device_id='.$otherDevice->id)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.device_id', $otherDevice->id);
    actingAs($user, 'api')->withHeaders($otherHeaders)->getJson('/api/hr/attendance/access-groups?company_id='.$unassignedCompany->id)
        ->assertForbidden();
    actingAs($user, 'api')->withHeaders($otherHeaders)->getJson('/api/hr/attendance/maintenance-commands?company_id='.$otherCompany->id)
        ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.company_id', $otherCompany->id);
    actingAs($user, 'api')->getJson('/api/hr/attendance/maintenance-commands')
        ->assertForbidden();

    config(['hr.features.attendance_ingestion' => true]);
    actingAs($user, 'api')->withHeaders($otherHeaders)->postJson('/api/hr/attendance/devices/'.$otherDevice->id.'/sync', ['days' => 2])
        ->assertUnprocessable();
    actingAs($user, 'api')->withHeaders($otherHeaders)->postJson('/api/hr/attendance/devices/'.$otherDevice->id.'/probe')
        ->assertUnprocessable();
    actingAs($user, 'api')->postJson('/api/hr/attendance/devices/'.$otherDevice->id.'/sync', [
        'company_id' => $company->id,
        'days' => 2,
    ])->assertForbidden();
    actingAs($user, 'api')->withHeaders($otherHeaders)->postJson('/api/hr/attendance/devices/'.$otherDevice->id.'/probe', [
        'company_id' => $company->id,
    ])->assertForbidden();

    actingAs($user, 'api')->withHeaders($otherHeaders)->postJson('/api/hr/attendance/access-groups', [
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
    $created = actingAs($user, 'api')->withHeaders($otherHeaders)->postJson('/api/hr/attendance/devices/'.$otherDevice->id.'/reboot-requests', $payload)
        ->assertCreated()->assertJsonPath('data.company_id', $otherCompany->id)->json('data');
    actingAs($user, 'api')->withHeaders($otherHeaders)->postJson('/api/hr/attendance/devices/'.$otherDevice->id.'/reboot-requests', $payload)
        ->assertOk()->assertJsonPath('data.id', $created['id']);
    actingAs($user, 'api')->withHeaders($otherHeaders)->postJson('/api/hr/attendance/devices/'.$otherDevice->id.'/reboot-requests', array_replace($payload, ['reason' => 'Different request']))
        ->assertConflict();

    $device->forceFill(['capabilities' => [
        'maintenance' => ['reboot' => true],
        'last_identity_probe_at' => now()->toIso8601String(),
    ]])->save();
    actingAs($user, 'api')->withHeaders($localHeaders)->postJson('/api/hr/attendance/devices/'.$device->id.'/reboot-requests', [
        'reason' => 'Verified maintenance window.',
        'typed_confirmation' => $device->site_code,
        'idempotency_key' => 'reboot-'.$otherCompany->id,
    ])->assertConflict();
});

it('lets device and maintenance viewers fetch authorized company options', function () {
    [, $company] = hr_seed_admin_actor();
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('hr.attendance.maintenance.execute');
    $staff = Staff::factory()->create(['user_id' => $viewer->id, 'company_id' => $company->id]);
    UserContext::create([
        'user_id' => $viewer->id, 'context_type' => 'staff', 'context_id' => $staff->id,
        'is_active' => true, 'created_user_id' => $viewer->id,
    ]);

    actingAs($viewer, 'api')->getJson('/api/hr/attendance/company-options')
        ->assertOk()->assertJsonPath('data.0.value', $company->id);
});

it('locks active Staff through terminal provisioning and rejects former Staff', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    config(['hr.features.attendance_ingestion' => true]);
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id, 'provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'status' => 'active',
    ]);
    $active = Staff::factory()->create(['company_id' => $company->id, 'code' => 'HIKV-ACTIVE-1']);
    $former = Staff::factory()->create(['company_id' => $company->id, 'code' => 'HIKV-FORMER-1', 'employment_ended_at' => now()->subDay()]);
    $former->delete();
    $baselineTransactionLevel = DB::transactionLevel();
    $adapter = Mockery::mock(HikvisionIsapiAdapter::class)->makePartial();
    $adapter->shouldReceive('provisionPerson')->once()->andReturnUsing(function () use ($baselineTransactionLevel) {
        expect(DB::transactionLevel())->toBeGreaterThan($baselineTransactionLevel);
        return ['employee_no' => 'HIKV-ACTIVE-1', 'created' => true];
    });
    $adapter->shouldNotReceive('updatePerson');
    app()->instance(HikvisionIsapiAdapter::class, $adapter);

    actingAs($user, 'api')->postJson("/api/hr/attendance/devices/{$device->id}/people", ['staff_id' => $active->id])
        ->assertCreated()->assertJsonPath('data.employee_no', 'HIKV-ACTIVE-1');
    actingAs($user, 'api')->postJson("/api/hr/attendance/devices/{$device->id}/people", ['staff_id' => $former->id])
        ->assertNotFound();
    DB::table('hr_attendance_person_mappings')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $former->id, 'device_id' => $device->id,
        'provider_person_id' => 'HIKV-FORMER-1', 'employee_number_snapshot' => 'HIKV-FORMER-1', 'enrollment_status' => 'verified',
        'effective_from' => '2026-01-01', 'created_by' => $user->id, 'verified_by' => $user->id,
        'last_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    actingAs($user, 'api')->putJson("/api/hr/attendance/devices/{$device->id}/people/HIKV-FORMER-1", [
        'staff_id' => $former->id, 'enabled' => true, 'display_name' => 'Former Staff', 'reason' => 'Activation must remain blocked',
    ])->assertUnprocessable();
});

it('synchronizes former Staff as disabled while holding the Staff row lock', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    config(['hr.features.attendance_ingestion' => true]);
    $former = Staff::factory()->create(['company_id' => $company->id, 'employment_ended_at' => now()->subDay()]);
    $former->delete();
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id, 'provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'status' => 'active',
    ]);
    DB::table('hr_attendance_person_mappings')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $former->id, 'device_id' => $device->id,
        'provider_person_id' => 'HIKV-FORMER-SYNC', 'employee_number_snapshot' => 'HIKV-FORMER-SYNC', 'enrollment_status' => 'verified',
        'effective_from' => now($device->timezone)->toDateString(), 'created_by' => $user->id, 'verified_by' => $user->id,
        'last_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $baselineTransactionLevel = DB::transactionLevel();
    $adapter = Mockery::mock(HikvisionIsapiAdapter::class)->makePartial();
    $adapter->shouldReceive('setPersonEnabled')->once()->andReturnUsing(function ($device, $employeeNumber, $enabled) use ($baselineTransactionLevel) {
        expect($employeeNumber)->toBe('HIKV-FORMER-SYNC')
            ->and($enabled)->toBeFalse()
            ->and(DB::transactionLevel())->toBeGreaterThan($baselineTransactionLevel);
        return ['employee_no' => $employeeNumber, 'enabled' => $enabled];
    });
    app()->instance(HikvisionIsapiAdapter::class, $adapter);

    expect(app(SyncHikvisionPeople::class)->handle(app(AttendanceProviderManager::class)))->toBe(0);
});

it('prefers a device-specific Staff mapping over a company-wide terminal mapping', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    config(['hr.features.attendance_ingestion' => true]);
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id, 'provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'status' => 'active',
    ]);
    $companyWideStaff = Staff::factory()->create(['company_id' => $company->id, 'employment_ended_at' => now()->subDay()]);
    $companyWideStaff->delete();
    $deviceStaff = Staff::factory()->create(['company_id' => $company->id]);
    foreach ([[$companyWideStaff, null], [$deviceStaff, $device->id]] as [$staff, $deviceId]) {
        DB::table('hr_attendance_person_mappings')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id, 'device_id' => $deviceId,
            'provider_person_id' => 'HIKV-DEVICE-OVERRIDE', 'employee_number_snapshot' => 'HIKV-DEVICE-OVERRIDE',
            'enrollment_status' => 'verified', 'effective_from' => now($device->timezone)->toDateString(),
            'created_by' => $user->id, 'verified_by' => $user->id, 'last_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $adapter = Mockery::mock(HikvisionIsapiAdapter::class)->makePartial();
    $adapter->shouldReceive('setPersonEnabled')->once()->withArgs(fn ($actualDevice, $personId, $enabled) =>
        $actualDevice->id === $device->id && $personId === 'HIKV-DEVICE-OVERRIDE' && $enabled === true
    )->andReturn(['employee_no' => 'HIKV-DEVICE-OVERRIDE', 'enabled' => true]);
    app()->instance(HikvisionIsapiAdapter::class, $adapter);

    expect(app(SyncHikvisionPeople::class)->handle(app(AttendanceProviderManager::class)))->toBe(0);
});

it('skips ambiguous device-specific and company-wide Staff mappings during sync', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    config(['hr.features.attendance_ingestion' => true]);
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id, 'provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'status' => 'active',
    ]);
    $staff = collect(range(1, 5))->map(fn () => Staff::factory()->create(['company_id' => $company->id]));
    $rows = [
        [$staff[0], $device->id, 'HIKV-AMBIGUOUS-DEVICE'],
        [$staff[1], $device->id, 'HIKV-AMBIGUOUS-DEVICE'],
        [$staff[2], null, 'HIKV-AMBIGUOUS-DEVICE'],
        [$staff[3], null, 'HIKV-AMBIGUOUS-COMPANY'],
        [$staff[4], null, 'HIKV-AMBIGUOUS-COMPANY'],
    ];
    foreach ($rows as [$member, $deviceId, $providerPersonId]) {
        DB::table('hr_attendance_person_mappings')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $member->id, 'device_id' => $deviceId,
            'provider_person_id' => $providerPersonId, 'employee_number_snapshot' => $providerPersonId,
            'enrollment_status' => 'verified', 'effective_from' => now($device->timezone)->toDateString(),
            'created_by' => $user->id, 'verified_by' => $user->id, 'last_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $adapter = Mockery::mock(HikvisionIsapiAdapter::class)->makePartial();
    $adapter->shouldNotReceive('setPersonEnabled');
    app()->instance(HikvisionIsapiAdapter::class, $adapter);

    expect(app(SyncHikvisionPeople::class)->handle(app(AttendanceProviderManager::class)))->toBe(0);
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

it('replays a reboot approval only for the same approver without changing its evidence', function () {
    [$requester, $company] = hr_seed_admin_actor([], true);
    $approver = User::factory()->create();
    $approver->assignRole('admin');
    $staff = Staff::factory()->create(['user_id' => $approver->id, 'company_id' => $company->id, 'staff_type' => 'admin']);
    UserContext::create([
        'user_id' => $approver->id, 'context_type' => 'staff', 'context_id' => $staff->id,
        'is_active' => true, 'created_user_id' => $approver->id,
    ]);
    $device = AttendanceDevice::factory()->create(['company_id' => $company->id, 'capabilities' => [
        'maintenance' => ['reboot' => true], 'last_identity_probe_at' => now()->toIso8601String(),
    ]]);
    $commandId = (string) Str::uuid();
    DB::table('hr_attendance_device_maintenance_commands')->insert([
        'id' => $commandId, 'company_id' => $company->id, 'device_id' => $device->id,
        'command_type' => 'reboot', 'status' => 'pending_approval', 'reason' => 'Scheduled maintenance',
        'typed_confirmation' => $device->site_code, 'idempotency_key' => 'reboot-approval-'.$commandId,
        'requested_by' => $requester->id, 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    config(['hr.features.hikvision_maintenance_commands' => true]);
    $url = '/api/hr/attendance/maintenance-commands/'.$commandId.'/approve';

    actingAs($approver, 'api')->postJson($url, [])->assertOk()->assertJsonPath('data.status', 'approved_pending_execution');
    $approvedAt = DB::table('hr_attendance_device_maintenance_commands')->where('id', $commandId)->value('approved_at');
    actingAs($approver, 'api')->postJson($url, [])->assertOk()->assertJsonPath('data.status', 'approved_pending_execution');
    expect(DB::table('hr_attendance_device_maintenance_commands')->where('id', $commandId)->value('approved_at'))->toBe($approvedAt);

    $otherApprover = User::factory()->create();
    $otherApprover->assignRole('admin');
    $otherStaff = Staff::factory()->create(['user_id' => $otherApprover->id, 'company_id' => $company->id, 'staff_type' => 'admin']);
    UserContext::create([
        'user_id' => $otherApprover->id, 'context_type' => 'staff', 'context_id' => $otherStaff->id,
        'is_active' => true, 'created_user_id' => $otherApprover->id,
    ]);
    actingAs($otherApprover, 'api')->postJson($url, [])->assertStatus(409);
});
