<?php

use App\Models\Company;
use App\Models\Sales\SalesOpportunity;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('defaults company options to the authorized active default and scopes the opportunity list', function () {
    [$actor, $defaultCompany] = hr_seed_admin_actor(['name' => 'Default CRM company']);
    $actor->givePermissionTo(Permission::findByName('sales.crm.view', 'api'));
    $actor->givePermissionTo(Permission::findByName('sales.crm.view-all', 'api'));
    $otherCompany = Company::create(['name' => 'Other CRM company', 'is_active' => true, 'is_default' => false]);

    $makeOpportunity = function (Company $company, string $code) use ($actor): SalesOpportunity {
        $staff = Staff::factory()->create(['company_id' => $company->id]);
        $profile = SalesProfile::query()->create([
            'company_id' => $company->id, 'staff_id' => $staff->id, 'sales_code' => $code,
            'status' => 'active', 'effective_from' => now()->subDay(), 'staff_category_snapshot' => 'Sales',
            'reporting_currency' => 'LKR', 'acquisition_eligible' => true, 'created_user_id' => $actor->id,
        ]);
        return SalesOpportunity::query()->create([
            'company_id' => $company->id, 'owner_sales_profile_id' => $profile->id,
            'opportunity_number' => $code.'-OPP', 'name' => $code.' opportunity', 'source' => 'manual',
            'created_user_id' => $actor->id,
        ]);
    };
    $makeOpportunity($defaultCompany, 'DEFAULT');
    $otherOpportunity = $makeOpportunity($otherCompany, 'OTHER');

    actingAs($actor, 'api')->getJson('/api/sales/opportunity-company-options')
        ->assertOk()->assertJsonPath('data.data.0.value', (string) $defaultCompany->id)
        ->assertJsonPath('data.data.0.metadata.is_default', true);
    actingAs($actor, 'api')->getJson('/api/sales/opportunities')->assertUnprocessable();
    actingAs($actor, 'api')->getJson('/api/sales/opportunities?company_id='.$otherCompany->id)
        ->assertOk()->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.id', (string) $otherOpportunity->id);

    $unscoped = User::factory()->create();
    $unscoped->givePermissionTo(Permission::findByName('sales.crm.view', 'api'));
    $limitedStaff = Staff::factory()->create(['company_id' => $defaultCompany->id, 'user_id' => $unscoped->id]);
    UserContext::create([
        'user_id' => $unscoped->id, 'context_type' => 'staff', 'context_id' => $limitedStaff->id,
        'is_active' => true, 'created_user_id' => $actor->id,
    ]);
    SalesProfile::query()->create([
        'company_id' => $defaultCompany->id, 'staff_id' => $limitedStaff->id, 'sales_code' => 'LIMITED-CRM',
        'status' => 'active', 'effective_from' => now()->subDay(), 'staff_category_snapshot' => 'Sales',
        'reporting_currency' => 'LKR', 'acquisition_eligible' => true, 'created_user_id' => $actor->id,
    ]);
    actingAs($unscoped, 'api')->getJson('/api/sales/opportunities?company_id='.$otherCompany->id)->assertForbidden();
    actingAs($unscoped, 'api')->getJson('/api/sales/opportunity-company-options')
        ->assertOk()->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.value', (string) $defaultCompany->id);
});
