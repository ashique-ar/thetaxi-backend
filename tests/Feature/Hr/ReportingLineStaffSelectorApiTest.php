<?php

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and hydrates active current Staff across the installation', function () {
    [$admin] = hr_seed_admin_actor();
    config(['hr.features.people_core' => true]);
    $staff = Staff::factory()->create(['code' => 'REPORT-001']);
    $inactive = Staff::factory()->former()->create(['code' => 'REPORT-OLD']);
    $now = now();
    DB::table('hr_employment_spells')->insert([
        'id' => (string) Str::uuid(), 'staff_id' => $staff->id, 'spell_number' => 1,
        'joined_at' => '2020-01-01', 'service_date' => '2020-01-01', 'gratuity_service_start' => '2020-01-01',
        'status' => 'active', 'created_user_id' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $other = Staff::factory()->create(['code' => 'REPORT-OTHER']);
    $url = '/api/hr/organization/reporting-line-staff-selector-options';

    $response = actingAs($admin, 'api')->getJson($url.'?search=REPORT-001&per_page=50')->assertOk()
        ->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.value', $staff->id)->assertJsonPath('data.data.0.status', 'active');
    expect(array_keys($response->json('data.data.0')))->toBe(['value', 'label', 'status'])
        ->and($response->json('data.data.0.label'))->toContain('REPORT-001');
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$staff->id)->assertOk()->assertJsonPath('data.data.0.value', $staff->id);
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$other->id)->assertOk()->assertJsonPath('data.data.0.value', $other->id);
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$inactive->id)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'?per_page=51')->assertUnprocessable();
});
