<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('limits workforce planning selector options to the selected Staff company', function () {
    [$admin, $firstCompany] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.workforce-planning.manage');
    $secondCompany = Company::create(['name' => 'Selected workforce planning company']);
    $secondStaff = Staff::factory()->create(['user_id' => $admin->id, 'company_id' => $secondCompany->id]);
    $context = UserContext::create([
        'user_id' => $admin->id, 'context_type' => 'staff', 'context_id' => $secondStaff->id,
        'is_active' => true, 'created_user_id' => $admin->id,
    ]);

    $firstUnit = (string) Str::uuid();
    $secondUnit = (string) Str::uuid();
    foreach ([[$firstUnit, $firstCompany->id, 'FIRST-UNIT', 'First company unit'], [$secondUnit, $secondCompany->id, 'SELECTED-UNIT', 'Selected company unit']] as [$id, $companyId, $code, $name]) {
        DB::table('hr_organization_units')->insert([
            'id' => $id, 'company_id' => $companyId, 'unit_type' => 'department', 'code' => $code, 'name' => $name,
            'timezone' => 'Asia/Colombo', 'status' => 'active', 'effective_from' => '2020-01-01',
            'created_user_id' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $url = '/api/hr/analytics/workforce-planning/reference-options?record_type=organization_unit';
    actingAs($admin, 'api')->getJson($url)->assertForbidden();
    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson($url.'&search=company')->assertOk()->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.value', $secondUnit)
        ->assertJsonPath('data.data.0.label', 'Selected company unit');
});
