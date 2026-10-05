<?php

use App\Models\Sales\SalesCompanyFeatureSetting;
use App\Models\Sales\SalesPolicySetting;
use App\Models\Sales\SalesStaffCategoryDefinition;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns screen fields to viewers and reveals policy rationale only to makers and approvers', function () {
    [$maker, $company] = hr_seed_admin_actor(['name' => 'Sales Policy Privacy Company']);
    $viewerStaff = Staff::factory()->create(['company_id' => $company->id]);
    $viewer = $viewerStaff->user;
    UserContext::query()->create([
        'user_id' => $viewer->id, 'context_type' => 'staff', 'context_id' => $viewerStaff->id,
        'is_active' => true, 'created_user_id' => $maker->id,
    ]);
    $viewer->givePermissionTo(Permission::findByName('sales.policy-settings.view', 'api'));
    $reason = 'Approved business decision is recorded here.';

    SalesPolicySetting::query()->create([
        'company_id' => $company->id, 'policy_kind' => 'business_timezone', 'version' => 1,
        'status' => 'draft', 'business_timezone' => 'Asia/Colombo', 'reason' => $reason,
        'created_by' => $maker->id,
    ]);
    SalesCompanyFeatureSetting::query()->create([
        'company_id' => $company->id, 'feature_key' => 'crm', 'version' => 1, 'enabled' => true,
        'status' => 'draft', 'reason' => $reason, 'created_by' => $maker->id,
    ]);
    SalesStaffCategoryDefinition::query()->create([
        'company_id' => $company->id, 'category_name' => 'Sales Specialist', 'status' => 'draft',
        'reason' => $reason, 'created_by' => $maker->id,
    ]);

    $url = '/api/sales/policy-settings?company_id='.$company->id;
    $viewerResponse = actingAs($viewer, 'api')->getJson($url)->assertOk();
    $viewerPolicy = $viewerResponse->json('data.policy_settings.business_timezone.0');
    $viewerFeature = $viewerResponse->json('data.company_features.0');
    $viewerCategory = $viewerResponse->json('data.staff_categories.0');
    expect(array_keys($viewerPolicy))->toBe([
        'id', 'policy_kind', 'version', 'status', 'fx_quote_base', 'fx_calculation_mode', 'fx_max_rate_age_hours',
        'fx_rounding_scale', 'dispute_response_days', 'profile_export_retention_days', 'business_timezone', 'approved_at',
    ]);
    expect(array_keys($viewerFeature))->toBe(['id', 'feature_key', 'version', 'enabled', 'status']);
    expect(array_keys($viewerCategory))->toBe(['id', 'category_name', 'status']);
    expect($viewerPolicy)->not->toHaveKey('reason');
    expect($viewerFeature)->not->toHaveKey('reason');
    expect($viewerCategory)->not->toHaveKey('reason');

    $viewer->givePermissionTo(Permission::findByName('sales.policy-settings.approve', 'api'));
    $reviewerResponse = actingAs($viewer, 'api')->getJson($url)->assertOk();
    expect($reviewerResponse->json('data.policy_settings.business_timezone.0.reason'))->toBe($reason)
        ->and($reviewerResponse->json('data.company_features.0.reason'))->toBe($reason)
        ->and($reviewerResponse->json('data.staff_categories.0.reason'))->toBe($reason)
        ->and($reviewerResponse->json('data.policy_settings.business_timezone.0'))->not->toHaveKey('created_by')
        ->and($reviewerResponse->json('data.policy_settings.business_timezone.0'))->not->toHaveKey('approved_by')
        ->and($reviewerResponse->json('data.staff_categories.0'))->not->toHaveKey('retired_by');
});
