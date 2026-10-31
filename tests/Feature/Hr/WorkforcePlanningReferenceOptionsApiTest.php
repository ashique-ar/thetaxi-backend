<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and hydrates organization units across the installation', function () {
    [$admin] = hr_seed_admin_actor();
    $unitId = (string) Str::uuid();
    DB::table('hr_organization_units')->insert([
        'id' => $unitId, 'unit_type' => 'department', 'code' => 'UNIT-OPS',
        'name' => 'Operations Unit', 'timezone' => 'Asia/Colombo', 'status' => 'active',
        'effective_from' => '2020-01-01', 'created_user_id' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $otherUnitId = (string) Str::uuid();
    DB::table('hr_organization_units')->insert([
        'id' => $otherUnitId, 'unit_type' => 'department', 'code' => 'UNIT-OTHER',
        'name' => 'Other Unit', 'timezone' => 'Asia/Colombo', 'status' => 'active',
        'effective_from' => '2020-01-01', 'created_user_id' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $url = '/api/hr/analytics/workforce-planning/reference-options?record_type=organization_unit';

    $response = actingAs($admin, 'api')->getJson($url.'&search=UNIT-OPS&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $unitId)->assertJsonPath('data.data.0.label', 'Operations Unit');
    expect(array_keys($response->json('data.data.0')))->toBe(['value', 'label', 'metadata', 'status']);
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$unitId.'&search=no-match')
        ->assertOk()->assertJsonPath('data.data.0.value', $unitId);
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$otherUnitId)
        ->assertOk()->assertJsonPath('data.data.0.value', $otherUnitId);
    actingAs($admin, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
    actingAs($admin, 'api')->getJson($url.'&page=0')->assertUnprocessable();
});
