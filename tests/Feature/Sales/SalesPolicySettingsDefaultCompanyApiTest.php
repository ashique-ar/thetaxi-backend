<?php

use App\Models\Sales\SalesPolicySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns installation-wide policy settings without a default company', function () {
    [$admin] = hr_seed_admin_actor(['name' => 'Sales Policy Installation']);
    $admin->givePermissionTo('sales.policy-settings.view');
    $setting = SalesPolicySetting::query()->create([
        'policy_kind' => 'business_timezone', 'version' => 1,
        'status' => 'draft', 'business_timezone' => 'Asia/Colombo', 'created_by' => $admin->id,
    ]);

    actingAs($admin, 'api')->getJson('/api/sales/policy-settings')
        ->assertOk()
        ->assertJsonPath('data.policy_settings.business_timezone.0.id', $setting->id)
        ->assertJsonMissingPath('data.company_options')
        ->assertJsonMissingPath('default_company_id');
});