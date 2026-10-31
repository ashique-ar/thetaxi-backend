<?php

use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('shows workforce planning selector options across the installation', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.workforce-planning.manage');
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')->firstOrFail();

    $firstUnit = (string) Str::uuid();
    $secondUnit = (string) Str::uuid();
    foreach ([[$firstUnit, 'FIRST-UNIT', 'First unit'], [$secondUnit, 'SELECTED-UNIT', 'Selected unit']] as [$id, $code, $name]) {
        DB::table('hr_organization_units')->insert([
            'id' => $id, 'unit_type' => 'department', 'code' => $code, 'name' => $name,
            'timezone' => 'Asia/Colombo', 'status' => 'active', 'effective_from' => '2020-01-01',
            'created_user_id' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $url = '/api/hr/analytics/workforce-planning/reference-options?record_type=organization_unit';
    actingAs($admin, 'api')->getJson($url)->assertOk();
    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson($url.'&search=unit')->assertOk()->assertJsonCount(2, 'data.data');
    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson($url.'&selected_id='.$secondUnit)->assertOk()->assertJsonPath('data.data.0.value', $secondUnit);
});
