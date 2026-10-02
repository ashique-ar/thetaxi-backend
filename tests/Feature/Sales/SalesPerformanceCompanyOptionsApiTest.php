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

it('searches and hydrates only authorized active Sales Profiles for performance forms', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Performance Profile Options Company']);
    $staff = Staff::factory()->create(['company_id' => $company->id, 'code' => 'PERF-STAFF-01']);
    $profile = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id,
        'sales_code' => 'PERF-OPTIONS-01', 'status' => 'active', 'effective_from' => now()->subDay(),
    ]);
    $former = Staff::factory()->former()->create(['company_id' => $company->id]);
    $formerProfile = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $former->id,
        'sales_code' => 'PERF-FORMER', 'status' => 'active', 'effective_from' => now()->subDays(40),
    ]);
    $otherCompany = Company::create(['name' => 'Other Performance Profile Company']);
    $otherStaff = Staff::factory()->create(['company_id' => $otherCompany->id]);
    $otherProfile = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $otherCompany->id, 'staff_id' => $otherStaff->id,
        'sales_code' => 'PERF-OTHER', 'status' => 'active', 'effective_from' => now()->subDay(),
    ]);
    $deletedCompany = Company::create(['name' => 'Deleted Performance Profile Company', 'deleted_at' => now()]);
    $url = '/api/sales/performance/profile-options?company_id='.$company->id;

    actingAs($admin, 'api')->getJson('/api/sales/performance/administration-context')->assertOk()->assertJsonMissingPath('data.profiles');
    actingAs($admin, 'api')->getJson($url.'&search=PERF-OPTIONS&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $profile->id)->assertJsonPath('data.data.0.label', 'PERF-OPTIONS-01')
        ->assertJsonPath('data.data.0.metadata.staff_code', 'PERF-STAFF-01');
    actingAs($admin, 'api')->getJson($url.'&selected_ids%5B%5D='.$profile->id.'&selected_ids%5B%5D='.$otherProfile->id)
        ->assertOk()->assertJsonPath('data.0.value', $profile->id)->assertJsonCount(1, 'data');
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$formerProfile->id)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
    actingAs($admin, 'api')->getJson('/api/sales/performance/profile-options?company_id='.$deletedCompany->id.'&per_page=1')->assertUnprocessable();
});
