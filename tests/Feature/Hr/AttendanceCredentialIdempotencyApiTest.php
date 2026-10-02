<?php

use App\Models\Company;
use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
use App\Models\User;
use App\Services\Hr\Attendance\HikvisionIsapiAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('replays only the same actor and exact credential request without storing the secret', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    $staff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id, 'provider' => 'hikvision', 'integration_mode' => 'direct_isapi',
        'status' => 'active', 'capabilities' => ['card_management' => true],
    ]);
    DB::table('hr_attendance_person_mappings')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id, 'device_id' => $device->id,
        'provider_person_id' => 'EMP-CREDENTIAL-1', 'employee_number_snapshot' => 'EMP-CREDENTIAL-1', 'enrollment_status' => 'verified',
        'effective_from' => '2026-01-01', 'created_by' => $user->id, 'verified_by' => $user->id,
        'last_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $adapter = Mockery::mock(HikvisionIsapiAdapter::class)->makePartial();
    $adapter->shouldReceive('cardOwner')->once()->andReturn(null);
    $adapter->shouldReceive('setCard')->once()->andReturnUsing(function () {
        expect(DB::table('hr_attendance_credential_events')->where('idempotency_key', 'credential-card-1')->value('status'))->toBe('pending');
        return ['accepted' => true];
    });
    app()->instance(HikvisionIsapiAdapter::class, $adapter);

    $url = "/api/hr/attendance/devices/{$device->id}/people/EMP-CREDENTIAL-1/cards";
    $payload = [
        'card_number' => '12345678', 'card_type' => 'normalCard', 'reason' => 'Issue staff card',
        'idempotency_key' => 'credential-card-1',
    ];
    $created = actingAs($user, 'api')->postJson($url, $payload)->assertCreated()->json('data');
    actingAs($user, 'api')->postJson($url, $payload)->assertOk()->assertJsonPath('data.id', $created['id']);
    actingAs($user, 'api')->postJson($url, array_replace($payload, ['reason' => 'Changed reason']))->assertConflict();

    $checksum = DB::table('hr_attendance_credential_events')->where('id', $created['id'])->value('request_checksum');
    expect($checksum)->not->toBeNull();
    expect($checksum)->not->toBe($payload['card_number']);
});

it('does not return credential events from another company when an idempotency key collides', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    $staff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id, 'provider' => 'hikvision', 'integration_mode' => 'direct_isapi',
        'status' => 'active', 'capabilities' => ['card_management' => true],
    ]);
    DB::table('hr_attendance_person_mappings')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id, 'device_id' => $device->id,
        'provider_person_id' => 'EMP-CREDENTIAL-2', 'employee_number_snapshot' => 'EMP-CREDENTIAL-2', 'enrollment_status' => 'verified',
        'effective_from' => '2026-01-01', 'created_by' => $user->id, 'verified_by' => $user->id,
        'last_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $foreignCompany = Company::create(['name' => 'Foreign Credential Company']);
    $foreignActor = User::factory()->create();
    $foreignStaff = Staff::factory()->create(['company_id' => $foreignCompany->id]);
    $foreignDevice = AttendanceDevice::factory()->create(['company_id' => $foreignCompany->id]);
    DB::table('hr_attendance_credential_events')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $foreignCompany->id, 'device_id' => $foreignDevice->id,
        'staff_id' => $foreignStaff->id, 'provider_person_id' => 'FOREIGN-EMP', 'credential_type' => 'card',
        'action' => 'set', 'credential_fingerprint' => str_repeat('a', 64), 'masked_reference' => '••••5678',
        'status' => 'delivered', 'reason' => 'Foreign credential action', 'idempotency_key' => 'shared-credential-key',
        'actor_user_id' => $foreignActor->id, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $adapter = Mockery::mock(HikvisionIsapiAdapter::class)->makePartial();
    $adapter->shouldNotReceive('cardOwner')->shouldNotReceive('setCard');
    app()->instance(HikvisionIsapiAdapter::class, $adapter);
    actingAs($user, 'api')->postJson("/api/hr/attendance/devices/{$device->id}/people/EMP-CREDENTIAL-2/cards", [
        'card_number' => '87654321', 'card_type' => 'normalCard', 'reason' => 'Issue staff card',
        'idempotency_key' => 'shared-credential-key',
    ])->assertConflict()->assertJsonMissing(['company_id' => $foreignCompany->id]);
});

it('blocks card and PIN issuance to former Staff', function () {
    [$user, $company] = hr_seed_admin_actor();
    $former = Staff::factory()->create(['company_id' => $company->id]);
    $former->forceFill(['employment_ended_at' => now()->subDay()])->save();
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id, 'provider' => 'hikvision', 'integration_mode' => 'direct_isapi',
        'status' => 'active', 'capabilities' => ['card_management' => true, 'pin_management' => true],
    ]);
    DB::table('hr_attendance_person_mappings')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $former->id, 'device_id' => $device->id,
        'provider_person_id' => 'FORMER-EMPLOYEE', 'employee_number_snapshot' => 'FORMER-EMPLOYEE', 'enrollment_status' => 'verified',
        'effective_from' => '2026-01-01', 'created_by' => $user->id, 'verified_by' => $user->id,
        'last_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $adapter = Mockery::mock(HikvisionIsapiAdapter::class)->makePartial();
    $adapter->shouldNotReceive('cardOwner')->shouldNotReceive('setCard')->shouldNotReceive('setPin');
    app()->instance(HikvisionIsapiAdapter::class, $adapter);

    $url = "/api/hr/attendance/devices/{$device->id}/people/FORMER-EMPLOYEE";
    actingAs($user, 'api')->postJson($url.'/cards', [
        'card_number' => '12345678', 'card_type' => 'normalCard', 'reason' => 'Issue card', 'idempotency_key' => 'former-card',
    ])->assertConflict();
    actingAs($user, 'api')->postJson($url.'/pin', [
        'pin' => '2468', 'reason' => 'Issue PIN', 'idempotency_key' => 'former-pin',
    ])->assertConflict();
});

it('fails closed when an identical terminal credential request is still pending', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    $staff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id, 'provider' => 'hikvision', 'integration_mode' => 'direct_isapi',
        'status' => 'active', 'capabilities' => ['card_management' => true],
    ]);
    DB::table('hr_attendance_person_mappings')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id, 'device_id' => $device->id,
        'provider_person_id' => 'EMP-PENDING-CREDENTIAL', 'employee_number_snapshot' => 'EMP-PENDING-CREDENTIAL', 'enrollment_status' => 'verified',
        'effective_from' => '2026-01-01', 'created_by' => $user->id, 'verified_by' => $user->id,
        'last_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $payload = ['card_number' => '12345678', 'card_type' => 'normalCard', 'reason' => 'Issue card', 'idempotency_key' => 'pending-credential'];
    $checksum = hash_hmac('sha256', json_encode([
        'company_id' => $company->id, 'device_id' => $device->id, 'staff_id' => $staff->id,
        'provider_person_id' => 'EMP-PENDING-CREDENTIAL', 'credential_type' => 'card', 'action' => 'set',
        'actor_user_id' => $user->id, 'request' => $payload,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (string) config('app.key'));
    DB::table('hr_attendance_credential_events')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'device_id' => $device->id, 'staff_id' => $staff->id,
        'provider_person_id' => 'EMP-PENDING-CREDENTIAL', 'credential_type' => 'card', 'action' => 'set',
        'credential_fingerprint' => hash_hmac('sha256', $payload['card_number'], (string) config('app.key')),
        'masked_reference' => '****5678', 'status' => 'pending', 'reason' => $payload['reason'],
        'idempotency_key' => $payload['idempotency_key'], 'request_checksum' => $checksum, 'actor_user_id' => $user->id,
        'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $adapter = Mockery::mock(HikvisionIsapiAdapter::class)->makePartial();
    $adapter->shouldNotReceive('cardOwner')->shouldNotReceive('setCard');
    app()->instance(HikvisionIsapiAdapter::class, $adapter);

    actingAs($user, 'api')->postJson("/api/hr/attendance/devices/{$device->id}/people/EMP-PENDING-CREDENTIAL/cards", $payload)
        ->assertConflict()->assertJsonPath('message', 'A prior terminal write is unresolved. Verify terminal state before retrying.');
});
