<?php

use App\Console\Commands\ProcessHikvisionAccessCommands;
use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
use App\Models\User;
use App\Services\Hr\Attendance\AttendanceProviderManager;
use App\Services\Hr\Attendance\HikvisionIsapiAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('blocks physical access grants to former Staff before approval or delivery', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    config(['hr.features.physical_access_commands' => true]);
    $former = Staff::factory()->create(['company_id' => $company->id, 'employment_ended_at' => now()->subDay()]);
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id, 'provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'status' => 'active',
    ]);

    actingAs($user, 'api')->postJson('/api/hr/attendance/access-commands', [
        'company_id' => $company->id, 'staff_id' => $former->id, 'device_id' => $device->id, 'command_type' => 'grant',
        'reason' => 'Grant reviewed access', 'idempotency_key' => 'former-staff-grant',
    ])->assertConflict();

    $commandId = (string) Str::uuid();
    $approver = User::factory()->create();
    DB::table('hr_attendance_access_commands')->insert([
        'id' => $commandId, 'company_id' => $company->id, 'staff_id' => $former->id, 'device_id' => $device->id,
        'command_type' => 'grant', 'status' => 'approved_pending_delivery', 'request_snapshot' => '{}',
        'idempotency_key' => 'former-staff-approved-grant', 'requested_by' => $user->id, 'approved_by' => $approver->id,
        'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $adapter = Mockery::mock(HikvisionIsapiAdapter::class)->makePartial();
    $adapter->shouldNotReceive('applyAccess');
    app()->instance(HikvisionIsapiAdapter::class, $adapter);

    app(ProcessHikvisionAccessCommands::class)->handle(app(AttendanceProviderManager::class));

    expect(DB::table('hr_attendance_access_commands')->where('id', $commandId)->value('status'))->toBe('blocked');
});

it('does not automatically repeat a physical access command after an ambiguous provider result', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    config(['hr.features.physical_access_commands' => true]);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id, 'provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'status' => 'active',
    ]);
    DB::table('hr_attendance_person_mappings')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id, 'device_id' => $device->id,
        'provider_person_id' => 'EMP-ACCESS-RETRY', 'employee_number_snapshot' => 'EMP-ACCESS-RETRY', 'enrollment_status' => 'verified',
        'effective_from' => now($device->timezone)->toDateString(), 'created_by' => $user->id, 'verified_by' => $user->id,
        'last_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $commandId = (string) Str::uuid();
    $approver = User::factory()->create();
    DB::table('hr_attendance_access_commands')->insert([
        'id' => $commandId, 'company_id' => $company->id, 'staff_id' => $staff->id, 'device_id' => $device->id,
        'command_type' => 'revoke', 'status' => 'approved_pending_delivery', 'request_snapshot' => '{}',
        'idempotency_key' => 'access-command-ambiguous', 'requested_by' => $user->id, 'approved_by' => $approver->id,
        'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $staleId = (string) Str::uuid();
    DB::table('hr_attendance_access_commands')->insert([
        'id' => $staleId, 'company_id' => $company->id, 'staff_id' => $staff->id, 'device_id' => $device->id,
        'command_type' => 'revoke', 'status' => 'delivering', 'request_snapshot' => '{}', 'attempt_count' => 1,
        'idempotency_key' => 'access-command-stale-claim', 'requested_by' => $user->id, 'approved_by' => $approver->id,
        'approved_at' => now(), 'created_at' => now()->subMinutes(5), 'updated_at' => now()->subMinutes(5),
    ]);
    $baselineTransactionLevel = DB::transactionLevel();
    $adapter = Mockery::mock(HikvisionIsapiAdapter::class)->makePartial();
    $adapter->shouldReceive('applyAccess')->once()->andReturnUsing(function () use ($commandId, $baselineTransactionLevel) {
        expect(DB::transactionLevel())->toBeGreaterThan($baselineTransactionLevel);
        expect(DB::table('hr_attendance_access_commands')->where('id', $commandId)->value('status'))->toBe('delivering');
        throw new RuntimeException('Provider response was lost.');
    });
    app()->instance(HikvisionIsapiAdapter::class, $adapter);
    $worker = app(ProcessHikvisionAccessCommands::class);
    $providers = app(AttendanceProviderManager::class);

    $worker->handle($providers);
    $worker->handle($providers);

    expect(DB::table('hr_attendance_access_commands')->where('id', $commandId)->value('status'))->toBe('delivery_unknown')
        ->and(DB::table('hr_attendance_access_commands')->where('id', $commandId)->value('attempt_count'))->toBe(1)
        ->and(DB::table('hr_attendance_access_delivery_attempts')->where('command_id', $commandId)->count())->toBe(1)
        ->and(DB::table('hr_attendance_access_commands')->where('id', $staleId)->value('status'))->toBe('delivery_unknown');
});

it('allows revocation for soft-deleted former Staff while keeping the Staff row locked through delivery', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    config(['hr.features.physical_access_commands' => true]);
    $former = Staff::factory()->create(['company_id' => $company->id, 'employment_ended_at' => now()->subDay()]);
    $former->delete();
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id, 'provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'status' => 'active',
    ]);
    DB::table('hr_attendance_person_mappings')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $former->id, 'device_id' => $device->id,
        'provider_person_id' => 'EMP-FORMER-REVOKE', 'employee_number_snapshot' => 'EMP-FORMER-REVOKE', 'enrollment_status' => 'verified',
        'effective_from' => now($device->timezone)->toDateString(), 'created_by' => $user->id, 'verified_by' => $user->id,
        'last_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $formerOptions = actingAs($user, 'api')->getJson('/api/hr/attendance/mapping-candidates?'.http_build_query([
        'company_id' => $company->id, 'include_former' => true, 'search' => $former->code,
    ]))->assertOk()->json('data.data');
    expect($formerOptions)->toHaveCount(1)
        ->and($formerOptions[0]['status'])->toBe('ended')
        ->and($formerOptions[0]['label'])->toStartWith('Former Staff · ');
    actingAs($user, 'api')->getJson('/api/hr/attendance/mapping-candidates?'.http_build_query([
        'company_id' => $company->id, 'search' => $former->code,
    ]))->assertOk()->assertJsonPath('data.data', []);

    actingAs($user, 'api')->postJson('/api/hr/attendance/access-commands', [
        'company_id' => $company->id, 'staff_id' => $former->id, 'device_id' => $device->id, 'command_type' => 'grant',
        'access_group_code' => 'FORMER-STAFF', 'reason' => 'Should stay blocked', 'idempotency_key' => 'former-staff-no-grant',
    ])->assertConflict();

    $response = actingAs($user, 'api')->postJson('/api/hr/attendance/access-commands', [
        'company_id' => $company->id, 'staff_id' => $former->id, 'device_id' => $device->id, 'command_type' => 'revoke',
        'reason' => 'Remove access after employment ended', 'idempotency_key' => 'former-staff-revoke',
    ])->assertCreated();
    $commandId = $response->json('data.id');
    DB::table('hr_attendance_access_commands')->where('id', $commandId)->update([
        'status' => 'approved_pending_delivery', 'approved_by' => User::factory()->create()->id, 'approved_at' => now(),
    ]);
    $baselineTransactionLevel = DB::transactionLevel();
    $adapter = Mockery::mock(HikvisionIsapiAdapter::class)->makePartial();
    $adapter->shouldReceive('applyAccess')->once()->andReturnUsing(function () use ($former, $baselineTransactionLevel) {
        expect(DB::transactionLevel())->toBeGreaterThan($baselineTransactionLevel)
            ->and(Staff::withTrashed()->whereKey($former->id)->lockForUpdate()->exists())->toBeTrue();
        return ['employee_no' => 'EMP-FORMER-REVOKE', 'granted' => false, 'door_no' => null, 'plan_template_no' => null];
    });
    app()->instance(HikvisionIsapiAdapter::class, $adapter);

    app(ProcessHikvisionAccessCommands::class)->handle(app(AttendanceProviderManager::class));

    expect(DB::table('hr_attendance_access_commands')->where('id', $commandId)->value('status'))->toBe('provider_accepted_unverified');
});
