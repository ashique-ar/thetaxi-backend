<?php

use App\Models\Company;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('reports the real availability of scoped Sales legal-entity options', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo([
        'sales.attributions.view', 'sales.attributions.view-all',
        'sales.payment-adjustments.create', 'sales.payment-adjustments.create-all',
        'sales.payment-finality.manage', 'sales.payment-finality.manage-all',
        'sales.policy-settings.view', 'sales.policy-settings.manage-all',
        'sales.commission-config.view', 'sales.commission-config.manage-all',
        'sales.performance.view', 'sales.performance.view-all',
    ]);
    $company = Company::create(['name' => 'Inactive Sales Options Company', 'is_active' => false]);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id,
        'sales_code' => 'INACTIVE-OPTIONS', 'status' => 'active', 'effective_from' => now()->subDay(),
    ]);

    foreach ([
        '/api/sales/attribution-company-options',
        '/api/sales/payment-adjustment-company-options',
        '/api/sales/payment-finality-company-options',
        '/api/sales/policy-settings/company-options',
        '/api/sales/commission-configuration/company-options',
        '/api/sales/performance/company-options',
    ] as $endpoint) {
        actingAs($admin, 'api')->getJson($endpoint.'?selected_id='.$company->id)->assertOk()
            ->assertJsonPath('data.data.0.value', $company->id)
            ->assertJsonPath('data.data.0.status', 'inactive')
            ->assertJsonPath('data.data.0.metadata.availability', 'Inactive');
    }
});
