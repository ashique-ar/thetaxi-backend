<?php

use App\Models\Company;
use App\Models\Sales\SalesCompanyFeatureSetting;
use App\Models\Sales\SalesProfile;
use App\Models\Sales\SalesOpportunity;
use App\Models\Sales\SalesCompanyFeatureSetting;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and hydrates only active scoped opportunity owners for companies with CRM enabled', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'CRM Owner Company']);
    config()->set('sales.features.crm', true);
    SalesCompanyFeatureSetting::query()->create([
        'company_id' => $company->id, 'feature_key' => 'crm', 'version' => 1,
        'enabled' => true, 'status' => 'approved', 'reason' => 'Feature test setup',
        'created_by' => $admin->id, 'approved_by' => $admin->id, 'approved_at' => now(),
    ]);
    $staff = Staff::factory()->create(['company_id' => $company->id, 'code' => 'CRM-STAFF-01']);
    UserContext::create(['user_id' => $staff->user_id, 'context_type' => 'staff', 'context_id' => $staff->id,
        'is_active' => true, 'created_user_id' => $admin->id]);
    $profile = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id,
        'sales_code' => 'CRM-OWNER-01', 'status' => 'active', 'effective_from' => now()->subDay(),
        'staff_category_snapshot' => 'Sales', 'reporting_currency' => 'LKR', 'acquisition_eligible' => true,
    ]);
    $unconfiguredCompany = Company::create(['name' => 'CRM Disabled Company']);
    $unconfiguredStaff = Staff::factory()->create(['company_id' => $unconfiguredCompany->id]);
    $unconfiguredProfile = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $unconfiguredCompany->id, 'staff_id' => $unconfiguredStaff->id,
        'sales_code' => 'CRM-DISABLED', 'status' => 'active', 'effective_from' => now()->subDay(),
        'staff_category_snapshot' => 'Sales', 'reporting_currency' => 'LKR', 'acquisition_eligible' => true,
    ]);
    $contextlessStaff = Staff::factory()->create(['company_id' => $company->id, 'code' => 'CRM-NO-CONTEXT']);
    $contextlessProfile = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $contextlessStaff->id,
        'sales_code' => 'CRM-NO-CONTEXT', 'status' => 'active', 'effective_from' => now()->subDay(),
        'staff_category_snapshot' => 'Sales', 'reporting_currency' => 'LKR', 'acquisition_eligible' => true,
    ]);
    $url = '/api/sales/opportunity-owner-options';

    $response = actingAs($admin, 'api')->getJson($url.'?search=CRM-OWNER&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $profile->id)
        ->assertJsonPath('data.data.0.label', 'CRM-OWNER-01 - CRM-STAFF-01')
        ->assertJsonPath('data.data.0.metadata.company', 'CRM Owner Company')
        ->assertJsonPath('data.data.0.record.company_id', $company->id);
    expect(array_keys($response->json('data.data.0')))->toBe(['value', 'label', 'metadata', 'record', 'status']);
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$profile->id.'&search=no-match')
        ->assertOk()->assertJsonPath('data.data.0.value', $profile->id);
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$unconfiguredProfile->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$contextlessProfile->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'?company_id='.$company->id.'&exclude_id='.$profile->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'?company_id='.$company->id.'&selected_id='.$profile->id.'&exclude_id='.$profile->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'?company_id='.$unconfiguredCompany->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'?per_page=51')->assertUnprocessable();
    actingAs($admin, 'api')->getJson('/api/sales/opportunity-administration-context')
        ->assertOk()->assertJsonMissingPath('data.profiles');
});

it('rejects a forged contextless CRM owner before recording an opportunity', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $admin->givePermissionTo('sales.crm.manage');
    config()->set('sales.features.sales_profiles', true);
    config()->set('sales.features.crm', true);
    SalesCompanyFeatureSetting::query()->create([
        'company_id' => $company->id, 'feature_key' => 'crm', 'version' => 1,
        'enabled' => true, 'status' => 'approved', 'reason' => 'Feature test setup',
        'created_by' => $admin->id, 'approved_by' => $admin->id, 'approved_at' => now(),
    ]);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    $profile = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id,
        'sales_code' => 'CRM-NO-CONTEXT', 'status' => 'active', 'effective_from' => now()->subDay(),
        'staff_category_snapshot' => 'Sales', 'reporting_currency' => 'LKR', 'acquisition_eligible' => true,
    ]);

    actingAs($admin, 'api')->postJson('/api/sales/opportunities', [
        'company_id' => $company->id, 'owner_sales_profile_id' => $profile->id, 'name' => 'Contextless owner',
        'source' => 'manual', 'expected_value_source' => 0, 'source_currency' => 'LKR',
        'expected_value_lkr' => 0, 'probability_percent' => 0,
    ])->assertUnprocessable();
    expect(SalesOpportunity::query()->exists())->toBeFalse();
});
