<?php

use App\Models\Sales\SalesCommissionPlanFamily;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function commission_config_actor(array $permissions = ['sales.commission-config.view', 'sales.commission-config.manage']): Staff
{
    [$admin] = hr_seed_admin_actor();
    $actor = Staff::factory()->create();
    UserContext::create(['user_id' => $actor->user_id, 'context_type' => 'staff', 'context_id' => $actor->id,
        'is_active' => true, 'created_user_id' => $admin->id]);
    $actor->user->givePermissionTo($permissions);

    return $actor;
}

it('does not expose a company selector for commission configuration', function () {
    $actor = commission_config_actor();

    actingAs($actor->user, 'api')->getJson('/api/sales/commission-configuration/company-options')->assertNotFound();
});

it('returns commission references without a company selection', function () {
    config()->set('sales.features.sales_profiles', true);
    $actor = commission_config_actor();
    $staff = Staff::factory()->create(['code' => 'GLOBAL-COMMISSION-STAFF']);
    $profile = SalesProfile::create(['staff_id' => $staff->id,
        'sales_code' => 'GLOBAL-COMMISSION-PROFILE', 'status' => 'active', 'effective_from' => now()->subDay()]);
    $family = SalesCommissionPlanFamily::create(['code' => 'GLOBAL-COMMISSION-PLAN', 'name' => 'Installation plan',
        'commission_category' => 'one_time', 'status' => 'approved', 'created_by' => $actor->user_id]);
    $foreignStaff = Staff::factory()->create(['code' => 'OTHER-INSTALLATION-STAFF']);
    SalesProfile::create(['staff_id' => $foreignStaff->id,
        'sales_code' => 'OTHER-LOCATION-PROFILE', 'status' => 'active', 'effective_from' => now()->subDay()]);

    actingAs($actor->user, 'api')->getJson('/api/sales/commission-configuration/reference-options?record_type=employee&selected_id='.$foreignStaff->id)
        ->assertOk()->assertJsonPath('data.data.0.value', $foreignStaff->id);
    actingAs($actor->user, 'api')->getJson('/api/sales/commission-configuration/reference-options?record_type=sales_profile&selected_id='.$profile->id.'&company_id=legacy-company-filter')
        ->assertOk()->assertJsonPath('data.data.0.value', $profile->id);
    actingAs($actor->user, 'api')->getJson('/api/sales/commission-configuration/reference-options?record_type=plan_family&selected_id='.$family->id)
        ->assertOk()->assertJsonPath('data.data.0.value', $family->id);
    actingAs($actor->user, 'api')->getJson('/api/sales/commission-configuration')->assertOk()
        ->assertJsonMissingPath('data.families.0.company_id')
        ->assertJsonMissingPath('data.assignments.0.company_id');
});

it('creates installation-wide commission rules without a tenant key', function () {
    $actor = commission_config_actor();
    $family = SalesCommissionPlanFamily::create([
        'code' => 'INSTALLATION-PLAN', 'name' => 'Installation plan', 'commission_category' => 'one_time',
        'status' => 'approved', 'created_by' => $actor->user_id,
    ]);
    actingAs($actor->user, 'api')->postJson('/api/sales/commission-plan-assignments', [
        'plan_family_id' => $family->id, 'scope_type' => 'installation',
        'effective_from' => today()->toDateString(),
    ])->assertCreated()->assertJsonPath('data.status', 'draft');

    expect(SalesCommissionPlanFamily::query()->where('code', 'INSTALLATION-PLAN')->exists())->toBeTrue();
});
