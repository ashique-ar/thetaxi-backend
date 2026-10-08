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

it('replays a physical access approval only for the same approver without changing its evidence', function () {
    [$requester, $company] = hr_seed_admin_actor();
    $approver = User::factory()->create();
    $approver->assignRole('admin');
    $staff = Staff::factory()->create(['user_id' => $approver->id, 'company_id' => $company->id, 'staff_type' => 'admin']);
    \App\Models\UserContext::create([
        'user_id' => $approver->id, 'context_type' => 'staff', 'context_id' => $staff->id,
        'is_active' => true, 'created_user_id' => $approver->id,
    ]);
    $device = AttendanceDevice::factory()->create(['company_id' => $company->id]);
    $commandId = (string) Str::uuid();
    DB::table('hr_attendance_access_commands')->insert([
        'id' => $commandId, 'company_id' => $company->id, 'staff_id' => $staff->id, 'device_id' => $device->id,
        'command_type' => 'revoke', 'status' => 'pending_approval', 'request_snapshot' => '{}',
        'idempotency_key' => 'approval-replay-'.$commandId, 'requested_by' => $requester->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    config(['hr.features.physical_access_commands' => true]);
    $url = '/api/hr/attendance/access-commands/'.$commandId.'/approve';

    actingAs($approver, 'api')->postJson($url, [])->assertOk()->assertJsonPath('data.status', 'approved_pending_delivery');
    $approvedAt = DB::table('hr_attendance_access_commands')->where('id', $commandId)->value('approved_at');
    actingAs($approver, 'api')->postJson($url, [])->assertOk()->assertJsonPath('data.status', 'approved_pending_delivery');
    expect(DB::table('hr_attendance_access_commands')->where('id', $commandId)->value('approved_at'))->toBe($approvedAt);

    $otherApprover = User::factory()->create();
    $otherApprover->assignRole('admin');
    $otherStaff = Staff::factory()->create(['user_id' => $otherApprover->id, 'company_id' => $company->id, 'staff_type' => 'admin']);
    \App\Models\UserContext::create([
        'user_id' => $otherApprover->id, 'context_type' => 'staff', 'context_id' => $otherStaff->id,
        'is_active' => true, 'created_user_id' => $otherApprover->id,
    ]);
    actingAs($otherApprover, 'api')->postJson($url, [])->assertStatus(409);
});

it('replays a pending manual command retry only for the same actor and reason', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    $staff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $device = AttendanceDevice::factory()->create(['company_id' => $company->id]);
    $commandId = (string) Str::uuid();
    DB::table('hr_attendance_access_commands')->insert([
        'id' => $commandId, 'company_id' => $company->id, 'staff_id' => $staff->id, 'device_id' => $device->id,
        'command_type' => 'revoke', 'status' => 'dead_letter', 'request_snapshot' => '{}',
        'idempotency_key' => 'manual-retry-'.$commandId, 'requested_by' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $url = '/api/hr/attendance/access-commands/'.$commandId.'/retry';
    $payload = ['reason' => 'Reviewed against the terminal record.'];

    actingAs($user, 'api')->postJson($url, $payload)->assertOk();
    $nextAttemptAt = DB::table('hr_attendance_access_commands')->where('id', $commandId)->value('next_attempt_at');
    expect(DB::table('hr_attendance_access_commands')->where('id', $commandId)->value('retry_requested_by'))->toBe($user->id);
    actingAs($user, 'api')->postJson($url, $payload)->assertOk();
    expect(DB::table('hr_attendance_access_commands')->where('id', $commandId)->value('next_attempt_at'))->toBe($nextAttemptAt);
    actingAs($user, 'api')->postJson($url, ['reason' => 'Different retry evidence'])->assertStatus(409);
    $audit = DB::table('activity_log')->where('description', 'attendance_access_command_retry_requested')->first();
    expect(json_decode($audit->properties, true))->toBe(['company_id' => $company->id, 'status' => 'retry_pending', 'command_type' => 'revoke'])
        ->and(DB::table('activity_log')->where('description', 'attendance_access_command_retry_requested')->count())->toBe(1);

    $otherActor = User::factory()->create();
    $otherActor->assignRole('admin');
    $otherStaff = Staff::factory()->create(['user_id' => $otherActor->id, 'company_id' => $company->id, 'staff_type' => 'admin']);
    \App\Models\UserContext::create([
        'user_id' => $otherActor->id, 'context_type' => 'staff', 'context_id' => $otherStaff->id,
        'is_active' => true, 'created_user_id' => $otherActor->id,
    ]);
    actingAs($otherActor, 'api')->postJson($url, $payload)->assertStatus(409);
});

it('allows an authorized manual retry to bring forward an automatic retry', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    $staff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $device = AttendanceDevice::factory()->create(['company_id' => $company->id]);
    $commandId = (string) Str::uuid();
    DB::table('hr_attendance_access_commands')->insert([
        'id' => $commandId, 'company_id' => $company->id, 'staff_id' => $staff->id, 'device_id' => $device->id,
        'command_type' => 'revoke', 'status' => 'retry_pending', 'request_snapshot' => '{}',
        'idempotency_key' => 'automatic-retry-'.$commandId, 'requested_by' => $user->id,
        'failure_message' => 'Temporary provider failure.', 'next_attempt_at' => now()->addHour(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($user, 'api')->postJson('/api/hr/attendance/access-commands/'.$commandId.'/retry', ['reason' => 'Reviewed for immediate retry.'])
        ->assertOk();
    expect(DB::table('hr_attendance_access_commands')->where('id', $commandId)->value('retry_requested_by'))->toBe($user->id);
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
