<?php

use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns searchable paginated terminal options only for the actor company', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    $first = AttendanceDevice::factory()->create(['company_id' => $company->id, 'site_code' => 'DEPOT-A', 'model' => 'DS-K1']);
    AttendanceDevice::factory()->create(['company_id' => $company->id, 'site_code' => 'DEPOT-B', 'model' => 'DS-K2']);
    $otherCompany = App\Models\Company::create(['name' => 'Other Attendance Co']);
    AttendanceDevice::factory()->create([
        'company_id' => $otherCompany->id,
        'site_code' => 'DEPOT-SECRET',
    ]);

    $response = actingAs($user, 'api')->getJson('/api/hr/attendance/device-options?search=DEPOT&per_page=1');

    $response->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.per_page', 1)
        ->assertJsonPath('data.data.0.value', $first->id)
        ->assertJsonPath('data.data.0.company_id', $company->id)
        ->assertJsonPath('data.data.0.label', 'DEPOT-A')
        ->assertJsonPath('data.data.0.metadata.model', 'DS-K1')
        ->assertJsonMissing(['label' => 'DEPOT-SECRET']);

    actingAs($user, 'api')->getJson('/api/hr/attendance/device-options?search=DEPOT&per_page=1&page=2')
        ->assertOk()
        ->assertJsonPath('data.current_page', 2)
        ->assertJsonPath('data.data.0.label', 'DEPOT-B');

    actingAs($user, 'api')->getJson('/api/hr/attendance/device-options?selected_id='.$first->id)
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.label', 'DEPOT-A');
});

it('does not hydrate a terminal option from another company', function () {
    [$user] = hr_seed_admin_actor([], true);
    $otherCompany = App\Models\Company::create(['name' => 'Other Attendance Co']);
    $device = AttendanceDevice::factory()->create(['company_id' => $otherCompany->id]);

    $response = actingAs($user, 'api')->getJson('/api/hr/attendance/device-options?selected_id='.$device->id);

    $response->assertOk()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('data.data', []);
});

it('allows staff.view-all users to select companies without a Staff record', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    $user = User::factory()->create();
    $user->givePermissionTo(['staff.view-all', 'hr.attendance.devices.view']);
    $first = App\Models\Company::create(['name' => 'Admin Attendance A']);
    $second = App\Models\Company::create(['name' => 'Admin Attendance B']);

    $response = actingAs($user, 'api')->getJson('/api/hr/attendance/company-options')->assertOk();

    expect(collect($response->json('data'))->pluck('value')->all())->toEqualCanonicalizing([$first->id, $second->id]);
});

it('returns searchable attendance Staff options only for active employees in the actor company', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    $activeUser = App\Models\User::factory()->create(['first_name' => 'Active', 'last_name' => 'Employee']);
    $active = Staff::factory()->create([
        'company_id' => $company->id,
        'code' => 'ATT-ACTIVE-01',
        'user_id' => $activeUser->id,
    ]);
    $scheduledUser = App\Models\User::factory()->create(['first_name' => 'Scheduled', 'last_name' => 'Employee']);
    $scheduled = Staff::factory()->create([
        'company_id' => $company->id,
        'code' => 'ATT-SCHEDULED-01',
        'user_id' => $scheduledUser->id,
    ]);
    $scheduled->forceFill(['employment_ended_at' => now()->addDay()])->save();
    $former = Staff::factory()->former()->create([
        'company_id' => $company->id,
        'code' => 'ATT-FORMER-01',
    ]);
    $otherCompany = App\Models\Company::create(['name' => 'Other Attendance Co']);
    $other = Staff::factory()->create([
        'company_id' => $otherCompany->id,
        'code' => 'ATT-OTHER-01',
    ]);

    $response = actingAs($user, 'api')->getJson('/api/hr/attendance/mapping-candidates?search=ATT-&per_page=50');

    $response->assertOk()
        ->assertJsonPath('data.data.0.value', $active->id)
        ->assertJsonPath('data.data.0.metadata.code', 'ATT-ACTIVE-01')
        ->assertJsonFragment(['value' => $scheduled->id])
        ->assertJsonMissing(['value' => $former->id])
        ->assertJsonMissing(['value' => $other->id]);
    expect($response->json('data.data.0.label'))->toContain('ATT-ACTIVE-01', 'Active Employee');
});

it('denies attendance selector access after the actor Staff employment ends', function () {
    [$user] = hr_seed_admin_actor([], true);
    $staff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $staff->forceFill(['employment_ended_at' => now()->subDay()])->save();

    actingAs($user, 'api')->getJson('/api/hr/attendance/mapping-candidates')
        ->assertForbidden()
        ->assertJsonPath('message', 'The authenticated user has no active Staff legal-entity context.');
});

it('uses an explicitly selected company only when the actor has active Staff membership there', function () {
    [$user] = hr_seed_admin_actor([], true);
    $otherCompany = App\Models\Company::create(['name' => 'Other Attendance Co']);
    Staff::factory()->create(['user_id' => $user->id, 'company_id' => $otherCompany->id]);
    $candidate = Staff::factory()->create(['company_id' => $otherCompany->id, 'code' => 'ATT-MEMBER-02']);

    actingAs($user, 'api')->getJson('/api/hr/attendance/mapping-candidates?company_id='.$otherCompany->id)
        ->assertOk()
        ->assertJsonFragment(['value' => $candidate->id]);

    actingAs($user, 'api')->getJson('/api/hr/attendance/mapping-candidates')
        ->assertForbidden()
        ->assertJsonPath('message', 'Select an authorized Staff legal entity for attendance access.');

    $unassignedCompany = App\Models\Company::create(['name' => 'Unassigned Attendance Co']);
    actingAs($user, 'api')->getJson('/api/hr/attendance/mapping-candidates?company_id='.$unassignedCompany->id)
        ->assertForbidden()
        ->assertJsonPath('message', 'Attendance data is outside your legal entity.');
    actingAs($user, 'api')->getJson('/api/hr/attendance/mapping-candidates?company_id='.Str::uuid())
        ->assertForbidden()
        ->assertJsonPath('message', 'Attendance data is outside your legal entity.');
});

it('allows device viewers to select only companies with active Staff membership', function () {
    [, $company] = hr_seed_admin_actor();
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('hr.attendance.devices.view');
    Staff::factory()->create(['user_id' => $viewer->id, 'company_id' => $company->id]);

    actingAs($viewer, 'api')->getJson('/api/hr/attendance/company-options')
        ->assertOk()->assertJsonPath('data.0.value', $company->id);
});

it('scopes attendance health and device alerts to the selected company and authorizes alert resolution by owner', function () {
    [$user, $company] = hr_seed_admin_actor();
    $otherCompany = App\Models\Company::create(['name' => 'Second Attendance Co']);
    Staff::factory()->create(['user_id' => $user->id, 'company_id' => $otherCompany->id]);
    $device = AttendanceDevice::factory()->create(['company_id' => $company->id]);
    $otherDevice = AttendanceDevice::factory()->create(['company_id' => $otherCompany->id]);
    $alertIds = [];

    foreach ([[$company->id, $device->id], [$otherCompany->id, $otherDevice->id]] as [$companyId, $deviceId]) {
        $alertId = (string) Str::uuid();
        $alertIds[$companyId] = $alertId;
        DB::table('hr_attendance_device_alerts')->insert([
            'id' => $alertId,
            'company_id' => $companyId,
            'device_id' => $deviceId,
            'alert_type' => 'device_offline',
            'severity' => 'medium',
            'status' => 'open',
            'message' => 'Device heartbeat is overdue.',
            'dedupe_key' => hash('sha256', $alertId),
            'detected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    actingAs($user, 'api')->getJson('/api/hr/attendance/health')->assertForbidden();
    actingAs($user, 'api')->getJson('/api/hr/attendance/health?company_id='.$otherCompany->id)
        ->assertOk()->assertJsonPath('data.devices.0.id', $otherDevice->id);
    actingAs($user, 'api')->getJson('/api/hr/attendance/device-alerts?company_id='.$otherCompany->id)
        ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $alertIds[$otherCompany->id]);
    actingAs($user, 'api')->getJson('/api/hr/attendance/device-alerts')
        ->assertForbidden();
    actingAs($user, 'api')->postJson('/api/hr/attendance/device-alerts/'.$alertIds[$otherCompany->id].'/resolve', [
        'note' => 'Verified on the selected terminal.',
    ])->assertOk();
    actingAs($user, 'api')->postJson('/api/hr/attendance/device-alerts/'.$alertIds[$otherCompany->id].'/resolve', [
        'note' => 'Duplicate resolution.',
    ])->assertStatus(409);

    $this->assertDatabaseHas('hr_attendance_device_alerts', [
        'id' => $alertIds[$otherCompany->id], 'status' => 'resolved', 'resolved_by' => $user->id,
    ]);
    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'hr-attendance', 'description' => 'attendance_device_alert_resolved',
    ]);
    expect(DB::table('activity_log')->where('description', 'attendance_device_alert_resolved')->count())->toBe(1);
});
