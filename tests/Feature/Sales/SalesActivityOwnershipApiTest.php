<?php

use App\Models\Company;
use App\Models\Sales\SalesActivity;
use App\Models\Sales\SalesCompanyFeatureSetting;
use App\Models\Sales\SalesMetricFact;
use App\Models\Sales\SalesOpportunity;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('records an authorized opportunity activity once and rejects cross-company references', function () {
    [$actor, $company] = hr_seed_admin_actor(['name' => 'Sales Activity Company']);
    $actor->givePermissionTo(Permission::findByName('sales.crm.manage', 'api'));
    $actor->givePermissionTo(Permission::findByName('sales.crm.manage-all', 'api'));
    config()->set('sales.features.crm', true);
    SalesCompanyFeatureSetting::query()->create([
        'company_id' => $company->id, 'feature_key' => 'crm', 'version' => 1,
        'enabled' => true, 'status' => 'approved', 'reason' => 'Feature test setup',
        'created_by' => $actor->id, 'approved_by' => $actor->id, 'approved_at' => now(),
    ]);

    $makeProfile = function (Company $ownerCompany, string $code) use ($actor): SalesProfile {
        $staff = Staff::factory()->create(['company_id' => $ownerCompany->id]);
        return SalesProfile::query()->create([
            'company_id' => $ownerCompany->id, 'staff_id' => $staff->id, 'sales_code' => $code,
            'status' => 'active', 'effective_from' => now()->subDay(), 'staff_category_snapshot' => 'Sales',
            'reporting_currency' => 'LKR', 'acquisition_eligible' => true, 'created_user_id' => $actor->id,
        ]);
    };
    $profile = $makeProfile($company, 'ACTIVITY-OWNER-A');
    $opportunity = SalesOpportunity::query()->create([
        'company_id' => $company->id, 'owner_sales_profile_id' => $profile->id,
        'opportunity_number' => 'ACTIVITY-OPP-A', 'name' => 'Activity opportunity A',
        'source' => 'manual', 'created_user_id' => $actor->id,
    ]);
    $otherCompany = Company::create(['name' => 'Other Sales Activity Company']);
    $otherProfile = $makeProfile($otherCompany, 'ACTIVITY-OWNER-B');
    $otherOpportunity = SalesOpportunity::query()->create([
        'company_id' => $otherCompany->id, 'owner_sales_profile_id' => $otherProfile->id,
        'opportunity_number' => 'ACTIVITY-OPP-B', 'name' => 'Activity opportunity B',
        'source' => 'manual', 'created_user_id' => $actor->id,
    ]);

    $payload = [
        'company_id' => $company->id, 'sales_profile_id' => $profile->id, 'opportunity_id' => $opportunity->id,
        'activity_type' => 'call', 'subject' => 'Discussed requirements', 'occurred_at' => now()->toISOString(),
        'source_system' => 'manual', 'source_reference' => (string) Str::uuid(),
    ];
    actingAs($actor, 'api')->postJson('/api/sales/activities', $payload)
        ->assertCreated()->assertJsonMissingPath('data.source_reference');
    actingAs($actor, 'api')->postJson('/api/sales/activities', $payload)->assertCreated();
    expect(SalesActivity::query()->count())->toBe(1)
        ->and(SalesMetricFact::query()->where('source_type', 'sales_activity')->count())->toBe(1);

    actingAs($actor, 'api')->postJson('/api/sales/activities', [
        ...$payload, 'subject' => 'Different facts with the same source key',
    ])->assertConflict();
    actingAs($actor, 'api')->postJson('/api/sales/activities', [
        ...$payload, 'opportunity_id' => $otherOpportunity->id, 'source_reference' => (string) Str::uuid(),
    ])->assertUnprocessable();

    $scopedActor = User::factory()->create();
    $scopedActor->givePermissionTo(Permission::findByName('sales.crm.manage', 'api'));
    $scopedStaff = Staff::factory()->create(['company_id' => $company->id, 'user_id' => $scopedActor->id]);
    UserContext::create([
        'user_id' => $scopedActor->id, 'context_type' => 'staff', 'context_id' => $scopedStaff->id,
        'is_active' => true, 'created_user_id' => $actor->id,
    ]);
    $scopedProfile = SalesProfile::query()->create([
        'company_id' => $company->id, 'staff_id' => $scopedStaff->id, 'sales_code' => 'ACTIVITY-OWNER-SCOPED',
        'status' => 'active', 'effective_from' => now()->subDay(), 'staff_category_snapshot' => 'Sales',
        'reporting_currency' => 'LKR', 'acquisition_eligible' => true, 'created_user_id' => $actor->id,
    ]);
    actingAs($scopedActor, 'api')->postJson('/api/sales/activities', [
        ...$payload, 'sales_profile_id' => $scopedProfile->id, 'source_reference' => (string) Str::uuid(),
    ])->assertForbidden();

    expect(SalesActivity::query()->count())->toBe(1)
        ->and(SalesMetricFact::query()->where('source_type', 'sales_activity')->count())->toBe(1);
});
