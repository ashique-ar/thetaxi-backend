<?php

use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns searchable paginated terminal options only for the actor company', function () {
    [$user, $company] = hr_seed_admin_actor();
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
    [$user] = hr_seed_admin_actor();
    $otherCompany = App\Models\Company::create(['name' => 'Other Attendance Co']);
    $device = AttendanceDevice::factory()->create(['company_id' => $otherCompany->id]);

    $response = actingAs($user, 'api')->getJson('/api/hr/attendance/device-options?selected_id='.$device->id);

    $response->assertOk()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('data.data', []);
});

it('returns searchable attendance Staff options only for active employees in the actor company', function () {
    [$user, $company] = hr_seed_admin_actor();
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
    [$user] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $staff->forceFill(['employment_ended_at' => now()->subDay()])->save();

    actingAs($user, 'api')->getJson('/api/hr/attendance/mapping-candidates')
        ->assertForbidden()
        ->assertJsonPath('message', 'The authenticated user has no active Staff legal-entity context.');
});

it('uses an explicitly selected company only when the actor has active Staff membership there', function () {
    [$user] = hr_seed_admin_actor();
    $otherCompany = App\Models\Company::create(['name' => 'Other Attendance Co']);
    Staff::factory()->create(['user_id' => $user->id, 'company_id' => $otherCompany->id]);
    $candidate = Staff::factory()->create(['company_id' => $otherCompany->id, 'code' => 'ATT-MEMBER-02']);

    actingAs($user, 'api')->getJson('/api/hr/attendance/mapping-candidates?company_id='.$otherCompany->id)
        ->assertOk()
        ->assertJsonFragment(['value' => $candidate->id]);

    actingAs($user, 'api')->getJson('/api/hr/attendance/mapping-candidates')
        ->assertForbidden()
        ->assertJsonPath('message', 'Select an authorized Staff legal entity for attendance access.');
});
