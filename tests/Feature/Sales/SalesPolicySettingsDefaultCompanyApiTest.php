<?php

use App\Models\Sales\SalesPolicySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('uses the active default company for policy settings when no company id is sent', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Default Sales Policy Company']);
    $admin->givePermissionTo('sales.policy-settings.view');
    $setting = SalesPolicySetting::query()->create([
        'company_id' => $company->id, 'policy_kind' => 'business_timezone', 'version' => 1,
        'status' => 'draft', 'business_timezone' => 'Asia/Colombo', 'created_by' => $admin->id,
    ]);

    actingAs($admin, 'api')->getJson('/api/sales/policy-settings')
        ->assertOk()
        ->assertJsonPath('data.policy_settings.business_timezone.0.id', $setting->id);
});
