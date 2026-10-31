<?php

use App\Models\Sales\SalesFeatureSetting;
use App\Models\Sales\SalesProfile;
use App\Models\Sales\SalesOpportunity;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches active installation-wide opportunity owners when CRM is enabled', function () {
    [$admin] = hr_seed_admin_actor();
    config()->set('sales.features.crm', true);
    SalesFeatureSetting::query()->create([
        'feature_key' => 'crm', 'version' => 1,
        'enabled' => true, 'status' => 'approved', 'reason' => 'Feature test setup',
        'created_by' => $admin->id, 'approved_by' => $admin->id, 'approved_at' => now(),
    ]);
    $staff = Staff::factory()->create(['code' => 'CRM-STAFF-01']);
    UserContext::create(['user_id' => $staff->user_id, 'context_type' => 'staff', 'context_id' => $staff->id,
        'is_active' => true, 'created_user_id' => $admin->id]);
    $profile = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'staff_id' => $staff->id,
        'sales_code' => 'CRM-OWNER-01', 'status' => 'active', 'effective_from' => now()->subDay(),
        'staff_category_snapshot' => 'Sales', 'reporting_currency' => 'LKR', 'acquisition_eligible' => true,
    ]);
    $otherStaff = Staff::factory()->create(['code' => 'CRM-STAFF-02']);
    UserContext::create(['user_id' => $otherStaff->user_id, 'context_type' => 'staff', 'context_id' => $otherStaff->id,
        'is_active' => true, 'created_user_id' => $admin->id]);
    $otherProfile = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'staff_id' => $otherStaff->id,
        'sales_code' => 'CRM-OTHER-OWNER', 'status' => 'active', 'effective_from' => now()->subDay(),
        'staff_category_snapshot' => 'Sales', 'reporting_currency' => 'LKR', 'acquisition_eligible' => true,
    ]);
    $contextlessStaff = Staff::factory()->create(['code' => 'CRM-NO-CONTEXT']);
    $contextlessProfile = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'staff_id' => $contextlessStaff->id,
        'sales_code' => 'CRM-NO-CONTEXT', 'status' => 'active', 'effective_from' => now()->subDay(),
        'staff_category_snapshot' => 'Sales', 'reporting_currency' => 'LKR', 'acquisition_eligible' => true,
    ]);
    $url = '/api/sales/opportunity-owner-options';

    $response = actingAs($admin, 'api')->getJson($url.'?search=CRM-OWNER&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $profile->id)
        ->assertJsonPath('data.data.0.label', 'CRM-OWNER-01 - CRM-STAFF-01');
    expect(array_keys($response->json('data.data.0')))->toBe(['value', 'label', 'status']);
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$profile->id.'&search=no-match')
        ->assertOk()->assertJsonPath('data.data.0.value', $profile->id);
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$otherProfile->id)
        ->assertOk()->assertJsonPath('data.data.0.value', $otherProfile->id);
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$contextlessProfile->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'?company_id=00000000-0000-0000-0000-000000000001&exclude_id='.$profile->id)
        ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.value', $otherProfile->id);
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$profile->id.'&exclude_id='.$profile->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'?company_id=00000000-0000-0000-0000-000000000001&per_page=10')
        ->assertOk()->assertJsonCount(2, 'data.data');
    actingAs($admin, 'api')->getJson($url.'?per_page=51')->assertUnprocessable();
    actingAs($admin, 'api')->getJson('/api/sales/opportunity-administration-context')->assertNotFound();
});

it('rejects a forged contextless CRM owner before recording an opportunity', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo('sales.crm.manage');
    config()->set('sales.features.sales_profiles', true);
    config()->set('sales.features.crm', true);
    SalesFeatureSetting::query()->create([
        'feature_key' => 'crm', 'version' => 1,
        'enabled' => true, 'status' => 'approved', 'reason' => 'Feature test setup',
        'created_by' => $admin->id, 'approved_by' => $admin->id, 'approved_at' => now(),
    ]);
    $staff = Staff::factory()->create();
    $profile = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'staff_id' => $staff->id,
        'sales_code' => 'CRM-NO-CONTEXT', 'status' => 'active', 'effective_from' => now()->subDay(),
        'staff_category_snapshot' => 'Sales', 'reporting_currency' => 'LKR', 'acquisition_eligible' => true,
    ]);

    actingAs($admin, 'api')->postJson('/api/sales/opportunities', [
        'owner_sales_profile_id' => $profile->id, 'name' => 'Contextless owner',
        'source' => 'manual', 'expected_value_source' => 0, 'source_currency' => 'LKR',
        'expected_value_lkr' => 0, 'probability_percent' => 0,
    ])->assertUnprocessable();
    expect(SalesOpportunity::query()->exists())->toBeFalse();
});
