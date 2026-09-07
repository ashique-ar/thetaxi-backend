<?php

use App\Models\Company;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and hydrates only companies with an effective active Sales Profile', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Performance Scope Company', 'city' => 'Colombo']);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id,
        'sales_code' => 'PERF-001', 'status' => 'active', 'effective_from' => now()->subDay(),
    ]);
    $withoutProfile = Company::create(['name' => 'No Active Performance Profile']);
    $url = '/api/sales/performance/company-options';

    $response = actingAs($admin, 'api')->getJson($url.'?search=Performance&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $company->id)
        ->assertJsonPath('data.data.0.label', 'Performance Scope Company')
        ->assertJsonPath('data.data.0.metadata.city', 'Colombo');
    expect(array_keys($response->json('data.data.0')))->toBe(['value', 'label', 'metadata', 'status']);
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$company->id.'&search=no-match')
        ->assertOk()->assertJsonPath('data.data.0.value', $company->id);
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$withoutProfile->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'?per_page=51')->assertUnprocessable();
});
