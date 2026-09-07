<?php

use App\Models\Company;
use App\Models\Sales\SalesProfile;
use App\Models\Sales\SalesStaffCategoryDefinition;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and hydrates only current Staff eligible for scoped Sales enrollment', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Profile Staff Company']);
    $actor = Staff::factory()->create(['company_id' => $company->id, 'staff_type' => 'sales', 'code' => 'SALE-001']);
    UserContext::create(['user_id' => $actor->user_id, 'context_type' => 'staff', 'context_id' => $actor->id,
        'is_active' => true, 'created_user_id' => $admin->id]);
    $actor->user->givePermissionTo(['sales.profiles.view', 'sales.profiles.manage']);
    SalesStaffCategoryDefinition::query()->create([
        'company_id' => $company->id, 'category_name' => 'sales', 'status' => 'approved',
        'reason' => 'Approved test category', 'created_by' => $admin->id, 'approved_by' => $admin->id, 'approved_at' => now(),
    ]);
    SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $actor->id,
        'sales_code' => 'PROFILE-STAFF-001', 'status' => 'active', 'effective_from' => now()->subDay(),
        'staff_category_snapshot' => 'sales', 'reporting_currency' => 'LKR', 'acquisition_eligible' => true,
    ]);
    $wrongCategory = Staff::factory()->create(['company_id' => $company->id, 'staff_type' => 'driver', 'code' => 'DRV-001']);
    $foreignCompany = Company::create(['name' => 'Foreign Profile Staff Company']);
    $foreign = Staff::factory()->create(['company_id' => $foreignCompany->id, 'staff_type' => 'sales']);
    $url = '/api/sales/profile-staff-options?company_id='.$company->id;

    $response = actingAs($actor->user, 'api')->getJson($url.'&search=SALE&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $actor->id)->assertJsonPath('data.data.0.metadata.staff_code', 'SALE-001')
        ->assertJsonPath('data.data.0.metadata.staff_category', 'sales');
    expect(array_keys($response->json('data.data.0')))->toBe(['value', 'label', 'metadata', 'status']);
    actingAs($actor->user, 'api')->getJson($url.'&selected_id='.$actor->id.'&search=no-match')->assertOk()
        ->assertJsonPath('data.data.0.value', $actor->id);
    actingAs($actor->user, 'api')->getJson($url.'&selected_id='.$wrongCategory->id)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson($url.'&selected_id='.$foreign->id)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
});
