<?php

use App\Models\Company;
use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
use App\Models\User;
use App\Services\Hr\Attendance\HikvisionIsapiAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    $baselineTransactionLevel = DB::transactionLevel();
    $adapter = Mockery::mock(HikvisionIsapiAdapter::class)->makePartial();
    $adapter->shouldReceive('cardOwner')->once()->andReturn(null);
    $adapter->shouldReceive('setCard')->once()->andReturnUsing(function () use ($baselineTransactionLevel) {
        expect(DB::transactionLevel())->toBeGreaterThan($baselineTransactionLevel);
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

it('refuses to roll back populated credential idempotency evidence', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    $staff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $device = AttendanceDevice::factory()->create(['company_id' => $company->id]);
    $id = (string) Str::uuid();
    $checksum = hash('sha256', 'credential-request-evidence');
    DB::table('hr_attendance_credential_events')->insert([
        'id' => $id,
        'company_id' => $company->id,
        'device_id' => $device->id,
        'staff_id' => $staff->id,
        'provider_person_id' => 'EMP-ROLLBACK-1',
        'credential_type' => 'card',
        'action' => 'set',
        'credential_fingerprint' => hash('sha256', 'card-fingerprint'),
        'masked_reference' => '••••1234',
        'status' => 'delivered',
        'reason' => 'Retained credential operation',
        'idempotency_key' => 'credential-rollback-1',
        'actor_user_id' => $user->id,
        'occurred_at' => now(),
        'request_checksum' => $checksum,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $governanceMigration = require database_path('migrations/2026_09_01_090000_add_hikvision_identity_and_credential_governance.php');
    expect(fn () => $governanceMigration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: retain attendance credential event evidence before removing Hikvision credential governance.');

    $migration = require database_path('migrations/2026_10_02_000002_add_request_checksum_to_attendance_credentials.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile attendance credential idempotency evidence first.');
    expect(Schema::hasColumn('hr_attendance_credential_events', 'request_checksum'))->toBeTrue()
        ->and(DB::table('hr_attendance_credential_events')->where('id', $id)->value('request_checksum'))->toBe($checksum);
});

it('refuses to roll back populated attendance identity dispositions', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    $device = AttendanceDevice::factory()->create(['company_id' => $company->id]);
    $id = (string) Str::uuid();
    DB::table('hr_attendance_identity_dispositions')->insert([
        'id' => $id,
        'company_id' => $company->id,
        'device_id' => $device->id,
        'provider_person_id' => 'UNMAPPED-ROLLBACK-1',
        'disposition' => 'reviewed_unmapped',
        'reason' => 'Retained identity review evidence',
        'reviewed_by' => $user->id,
        'reviewed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_09_01_090000_add_hikvision_identity_and_credential_governance.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: retain attendance identity disposition evidence before removing Hikvision credential governance.');
    expect(Schema::hasTable('hr_attendance_identity_dispositions'))->toBeTrue()
        ->and(DB::table('hr_attendance_identity_dispositions')->where('id', $id)->exists())->toBeTrue();
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
    $former->delete();
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
    $adapter->shouldReceive('cards')->once()->andReturn([['card_number' => '12345678', 'card_type' => 'normalCard']]);
    $adapter->shouldReceive('deleteCard')->once()->andReturn(['accepted' => true]);
    app()->instance(HikvisionIsapiAdapter::class, $adapter);

    $url = "/api/hr/attendance/devices/{$device->id}/people/FORMER-EMPLOYEE";
    actingAs($user, 'api')->postJson($url.'/cards', [
        'card_number' => '12345678', 'card_type' => 'normalCard', 'reason' => 'Issue card', 'idempotency_key' => 'former-card',
    ])->assertConflict();
    actingAs($user, 'api')->postJson($url.'/pin', [
        'pin' => '2468', 'reason' => 'Issue PIN', 'idempotency_key' => 'former-pin',
    ])->assertConflict();
    $fingerprint = hash_hmac('sha256', '12345678', (string) config('app.key'));
    actingAs($user, 'api')->deleteJson($url.'/cards/'.$fingerprint, [
        'reason' => 'Remove former Staff card', 'idempotency_key' => 'former-card-revoke',
    ])->assertOk();
});

it('rechecks Staff after reserving card issuance and leaves the key held if employment ended meanwhile', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    $staff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $device = AttendanceDevice::factory()->create([
        'company_id' => $company->id, 'provider' => 'hikvision', 'integration_mode' => 'direct_isapi',
        'status' => 'active', 'capabilities' => ['card_management' => true],
    ]);
    DB::table('hr_attendance_person_mappings')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id, 'device_id' => $device->id,
        'provider_person_id' => 'EMP-CREDENTIAL-EXIT-RACE', 'employee_number_snapshot' => 'EMP-CREDENTIAL-EXIT-RACE', 'enrollment_status' => 'verified',
        'effective_from' => '2026-01-01', 'created_by' => $user->id, 'verified_by' => $user->id,
        'last_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $adapter = Mockery::mock(HikvisionIsapiAdapter::class)->makePartial();
    $adapter->shouldReceive('cardOwner')->once()->andReturnUsing(function () use ($staff) {
        $staff->forceFill(['employment_ended_at' => now()->subSecond()])->save();
        return null;
    });
    $adapter->shouldNotReceive('setCard');
    app()->instance(HikvisionIsapiAdapter::class, $adapter);

    actingAs($user, 'api')->postJson("/api/hr/attendance/devices/{$device->id}/people/EMP-CREDENTIAL-EXIT-RACE/cards", [
        'card_number' => '12345678', 'card_type' => 'normalCard', 'reason' => 'Issue card', 'idempotency_key' => 'credential-exit-race',
    ])->assertConflict();

    expect(DB::table('hr_attendance_credential_events')->where('idempotency_key', 'credential-exit-race')->value('status'))->toBe('pending');
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
