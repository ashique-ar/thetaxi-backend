<?php

use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('rejects reporting assignments that outlive a member Staff employment', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $admin->givePermissionTo('sales.profiles.manage', 'sales.profiles.manage-all');
    $managerStaff = Staff::factory()->create(['company_id' => $company->id, 'staff_type' => 'sales']);
    $formerStaff = Staff::factory()->create([
        'company_id' => $company->id,
        'staff_type' => 'sales',
        'employment_ended_at' => now()->subDay(),
    ]);
    $manager = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $managerStaff->id,
        'sales_code' => 'TEAM-MANAGER-01', 'status' => 'active', 'effective_from' => now()->subMonth(),
        'staff_category_snapshot' => 'sales', 'reporting_currency' => 'LKR', 'acquisition_eligible' => true,
    ]);
    $member = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $formerStaff->id,
        'sales_code' => 'FORMER-MEMBER-01', 'status' => 'active', 'effective_from' => now()->subMonth(),
        'staff_category_snapshot' => 'sales', 'reporting_currency' => 'LKR', 'acquisition_eligible' => true,
    ]);

    actingAs($admin, 'api')->postJson('/api/sales/reporting-assignments', [
        'company_id' => $company->id,
        'manager_sales_profile_id' => $manager->id,
        'member_sales_profile_id' => $member->id,
        'effective_from' => now()->subDay()->toDateString(),
        'reason' => 'Attempted assignment to former Staff',
    ])->assertUnprocessable();

    $this->assertDatabaseMissing('sales_reporting_assignments', [
        'manager_sales_profile_id' => $manager->id,
        'member_sales_profile_id' => $member->id,
    ]);
});
