<?php

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('exposes a read-only accounting reconciliation summary within the authorized company scope', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Accounting reconciliation company']);
    $admin->givePermissionTo(['sales.commission-accounting.acknowledge', 'sales.commission-statements.view-all']);

    actingAs($admin, 'api')->getJson('/api/sales/commission-accounting/reconciliation?company_id='.$company->id)
        ->assertOk()
        ->assertJsonPath('data.summary.total', 0)
        ->assertJsonPath('data.summary.page_count', 0)
        ->assertJsonPath('data.data.total', 0);

    $inactive = Company::create(['name' => 'Inactive accounting company', 'is_active' => false]);
    actingAs($admin, 'api')->getJson('/api/sales/commission-accounting/reconciliation?company_id='.$inactive->id)
        ->assertUnprocessable();
    actingAs($admin, 'api')->postJson('/api/sales/commission-accounting/reconciliation')->assertStatus(405);
});
