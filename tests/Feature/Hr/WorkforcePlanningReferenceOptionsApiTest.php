<?php

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and hydrates organization units only within the active Staff legal entity', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Planning Selector Company']);
    $unitId = (string) Str::uuid();
    DB::table('hr_organization_units')->insert([
        'id' => $unitId, 'company_id' => $company->id, 'unit_type' => 'department', 'code' => 'UNIT-OPS',
        'name' => 'Operations Unit', 'timezone' => 'Asia/Colombo', 'status' => 'active',
        'effective_from' => '2020-01-01', 'created_user_id' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $foreign = Company::create(['name' => 'Foreign Planning Company']);
    $foreignUnitId = (string) Str::uuid();
    DB::table('hr_organization_units')->insert([
        'id' => $foreignUnitId, 'company_id' => $foreign->id, 'unit_type' => 'department', 'code' => 'UNIT-FOR',
        'name' => 'Foreign Unit', 'timezone' => 'Asia/Colombo', 'status' => 'active',
        'effective_from' => '2020-01-01', 'created_user_id' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $url = '/api/hr/analytics/workforce-planning/reference-options?record_type=organization_unit';

    $response = actingAs($admin, 'api')->getJson($url.'&search=UNIT-OPS&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $unitId)->assertJsonPath('data.data.0.label', 'Operations Unit');
    expect(array_keys($response->json('data.data.0')))->toBe(['value', 'label', 'metadata', 'status']);
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$unitId.'&search=no-match')
        ->assertOk()->assertJsonPath('data.data.0.value', $unitId);
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$foreignUnitId)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
    actingAs($admin, 'api')->getJson($url.'&page=0')->assertUnprocessable();
});
