<?php

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches active Staff and interval-covering positions across the installation', function () {
    [$admin] = hr_seed_admin_actor();
    config(['hr.features.people_core' => true]);
    $user = \App\Models\User::factory()->create(['first_name' => 'Acting', 'last_name' => 'Candidate']);
    $staff = Staff::factory()->create(['user_id' => $user->id, 'staff_type' => 'staff', 'employment_ended_at' => null]);
    DB::table('hr_employment_spells')->insert([
        'id' => (string) Str::uuid(), 'staff_id' => $staff->id, 'spell_number' => 1,
        'joined_at' => '2020-01-01', 'service_date' => '2020-01-01', 'gratuity_service_start' => '2020-01-01',
        'status' => 'active', 'created_user_id' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $unitId = (string) Str::uuid();
    $designationId = (string) Str::uuid();
    $positionId = (string) Str::uuid();
    DB::table('hr_organization_units')->insert([
        'id' => $unitId, 'unit_type' => 'department', 'code' => 'ACT-UNIT', 'name' => 'Acting Unit',
        'timezone' => 'Asia/Colombo', 'status' => 'active', 'effective_from' => '2020-01-01', 'created_user_id' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_designations')->insert([
        'id' => $designationId, 'code' => 'ACT-DESIGNATION', 'name' => 'Acting Designation',
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_positions')->insert([
        'id' => $positionId, 'organization_unit_id' => $unitId, 'designation_id' => $designationId,
        'position_number' => 'ACT-POS-001', 'title' => 'Acting Manager', 'headcount_limit' => 1, 'status' => 'active',
        'effective_from' => '2020-01-01', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_positions')->insert([
        'id' => (string) Str::uuid(), 'organization_unit_id' => $unitId, 'designation_id' => $designationId,
        'position_number' => 'ACT-POS-SHORT', 'title' => 'Short Coverage', 'headcount_limit' => 1, 'status' => 'active',
        'effective_from' => '2020-01-01', 'effective_until' => '2026-01-05', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $url = '/api/hr/organization/acting-appointment-reference-options';
    actingAs($admin, 'api')->getJson($url.'?record_type=staff&search=Acting%20Candidate')
        ->assertOk()->assertJsonPath('data.data.0.value', $staff->id)->assertJsonPath('data.data.0.label', 'Acting Candidate · '.$staff->code);
    actingAs($admin, 'api')->getJson($url.'?record_type=position&effective_from=2026-01-01&effective_until=2026-01-10&search=ACT-POS')
        ->assertOk()->assertJsonPath('data.data.0.value', $positionId)->assertJsonPath('data.data.0.label', 'ACT-POS-001 · Acting Manager');
    actingAs($admin, 'api')->getJson($url.'?record_type=position&effective_from=2026-01-01&effective_until=2026-01-10&search=SHORT')
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'?record_type=staff&selected_id='.$staff->id.'&exclude_id='.$staff->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    $foreignPositionId = (string) Str::uuid();
    DB::table('hr_positions')->insert([
        'id' => $foreignPositionId, 'organization_unit_id' => $unitId, 'designation_id' => $designationId,
        'position_number' => 'OTHER-ACT-POS', 'title' => 'Other Acting Manager', 'headcount_limit' => 1, 'status' => 'active',
        'effective_from' => '2020-01-01', 'created_at' => now(), 'updated_at' => now(),
    ]);
    actingAs($admin, 'api')->getJson($url.'?record_type=position&selected_id='.$foreignPositionId)
        ->assertOk()->assertJsonPath('data.data.0.value', $foreignPositionId);
    actingAs($admin, 'api')->getJson($url.'?record_type=position&per_page=51')->assertUnprocessable();
});
