<?php

use App\Models\Hr\Attendance\AttendanceDevice;
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
