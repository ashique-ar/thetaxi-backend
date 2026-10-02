<?php

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and hydrates readable organization references within the active Staff legal entity', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Organization Selector Company']);
    config(['hr.features.people_core' => true]);

    $unitId = (string) Str::uuid();
    $familyId = (string) Str::uuid();
    $gradeId = (string) Str::uuid();
    $inactiveGradeId = (string) Str::uuid();
    $designationId = (string) Str::uuid();
    DB::table('hr_organization_units')->insert([
        'id' => $unitId, 'company_id' => $company->id, 'unit_type' => 'department', 'code' => 'SEL-UNIT',
        'name' => 'Selector Operations', 'timezone' => 'Asia/Colombo', 'status' => 'active', 'effective_from' => '2020-01-01',
        'created_user_id' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_job_families')->insert([
        'id' => $familyId, 'company_id' => $company->id, 'code' => 'SEL-FAMILY', 'name' => 'Selector Family',
        'status' => 'active', 'effective_from' => '2020-01-01', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_job_grades')->insert([
        'id' => $gradeId, 'company_id' => $company->id, 'code' => 'SEL-GRADE', 'name' => 'Selector Grade', 'rank' => 2,
        'status' => 'active', 'effective_from' => '2020-01-01', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_job_grades')->insert([
        'id' => $inactiveGradeId, 'company_id' => $company->id, 'code' => 'OLD-GRADE', 'name' => 'Historical Grade', 'rank' => 1,
        'status' => 'inactive', 'effective_from' => '2020-01-01', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_designations')->insert([
        'id' => $designationId, 'company_id' => $company->id, 'job_family_id' => $familyId, 'job_grade_id' => $gradeId,
        'code' => 'SEL-DESIGNATION', 'name' => 'Selector Designation', 'status' => 'active', 'effective_from' => '2020-01-01',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $url = '/api/hr/organization/reference-options?record_type=';
    foreach ([
        'organization_unit' => [$unitId, 'Selector Operations'],
        'job_family' => [$familyId, 'Selector Family'],
        'job_grade' => [$gradeId, 'Selector Grade'],
        'designation' => [$designationId, 'Selector Designation'],
    ] as $recordType => [$id, $label]) {
        actingAs($admin, 'api')->getJson($url.$recordType.'&search=SEL-&per_page=1')->assertOk()
            ->assertJsonPath('data.data.0.value', $id)
            ->assertJsonPath('data.data.0.label', $label);
    }

    actingAs($admin, 'api')->getJson($url.'organization_unit&selected_id='.$unitId.'&search=no-match')
        ->assertOk()->assertJsonPath('data.data.0.value', $unitId);
    actingAs($admin, 'api')->getJson($url.'organization_unit&selected_id='.$unitId.'&exclude_id='.$unitId)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'job_grade&search=Historical')
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'job_grade&selected_id='.$inactiveGradeId)
        ->assertOk()->assertJsonPath('data.data.0.value', $inactiveGradeId)
        ->assertJsonPath('data.data.0.status', 'inactive');

    $foreign = Company::create(['name' => 'Foreign Organization Selector Company']);
    $foreignUnitId = (string) Str::uuid();
    DB::table('hr_organization_units')->insert([
        'id' => $foreignUnitId, 'company_id' => $foreign->id, 'unit_type' => 'department', 'code' => 'FOREIGN-UNIT',
        'name' => 'Foreign Unit', 'timezone' => 'Asia/Colombo', 'status' => 'active', 'effective_from' => '2020-01-01',
        'created_user_id' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    actingAs($admin, 'api')->getJson($url.'organization_unit&selected_id='.$foreignUnitId)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'job_grade&per_page=51')->assertUnprocessable();
    actingAs($admin, 'api')->getJson($url.'job_grade&page=0')->assertUnprocessable();
});
