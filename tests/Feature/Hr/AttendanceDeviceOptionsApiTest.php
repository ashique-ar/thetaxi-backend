<?php

use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns searchable paginated installation-wide device options without tenant metadata', function () {
    [$user] = hr_seed_admin_actor([], true);
    $first = AttendanceDevice::factory()->create(['site_code' => 'DEPOT-A', 'model' => 'DS-K1']);
    AttendanceDevice::factory()->create(['site_code' => 'DEPOT-B', 'model' => 'DS-K2']);
    AttendanceDevice::factory()->create(['site_code' => 'DEPOT-C', 'model' => 'DS-K3']);

    actingAs($user, 'api')->getJson('/api/hr/attendance/device-options?search=DEPOT&per_page=2')
        ->assertOk()
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.per_page', 2)
        ->assertJsonPath('data.data.0.value', $first->id)
        ->assertJsonPath('data.data.0.label', 'DEPOT-A')
        ->assertJsonPath('data.data.0.metadata.model', 'DS-K1')
        ->assertJsonMissingPath('data.data.0.company_id');

    actingAs($user, 'api')->getJson('/api/hr/attendance/device-options?search=DEPOT&per_page=2&page=2')
        ->assertOk()->assertJsonPath('data.current_page', 2);
    actingAs($user, 'api')->getJson('/api/hr/attendance/device-options?selected_id='.$first->id)
        ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.label', 'DEPOT-A');
});

it('returns active Staff mapping candidates across the installation and excludes former Staff by default', function () {
    [$user] = hr_seed_admin_actor([], true);
    $active = Staff::factory()->create(['code' => 'ATT-ACTIVE-01']);
    $former = Staff::factory()->former()->create(['code' => 'ATT-FORMER-01']);

    $response = actingAs($user, 'api')->getJson('/api/hr/attendance/mapping-candidates?search=ATT-&per_page=50')
        ->assertOk();
    $ids = collect($response->json('data.data'))->pluck('value');

    expect($ids)->toContain($active->id)->not->toContain($former->id)
        ->and($response->json('data.data.0'))->not->toHaveKey('company_id');
});

it('shows installation-wide health and lets an authorized user resolve any device alert', function () {
    [$user] = hr_seed_admin_actor();
    $device = AttendanceDevice::factory()->create();
    $alertId = (string) Str::uuid();
    DB::table('hr_attendance_device_alerts')->insert([
        'id' => $alertId,
        'device_id' => $device->id,
        'alert_type' => 'device_offline',
        'severity' => 'medium',
        'status' => 'open',
        'message' => 'Device heartbeat is overdue.',
        'dedupe_key' => hash('sha256', $alertId),
        'detected_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $headers = ['X-Active-Context-Type' => 'internal'];

    actingAs($user, 'api')->withHeaders($headers)->getJson('/api/hr/attendance/health')
        ->assertOk()->assertJsonPath('data.devices.0.id', $device->id);
    actingAs($user, 'api')->withHeaders($headers)->getJson('/api/hr/attendance/device-alerts')
        ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $alertId);
    actingAs($user, 'api')->withHeaders($headers)->postJson('/api/hr/attendance/device-alerts/'.$alertId.'/resolve', [
        'note' => 'Verified on the terminal.',
    ])->assertOk();

    $this->assertDatabaseHas('hr_attendance_device_alerts', [
        'id' => $alertId, 'status' => 'resolved', 'resolved_by' => $user->id,
    ]);
    $audit = DB::table('activity_log')->where('log_name', 'hr-attendance')
        ->where('description', 'attendance_device_alert_resolved')->first();
    expect(json_decode($audit->properties, true))->toBe([
        'status' => 'resolved',
        'alert_type' => 'device_offline',
    ]);
});
