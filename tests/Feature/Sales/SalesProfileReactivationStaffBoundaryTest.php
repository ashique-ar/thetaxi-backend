<?php

use App\Models\Sales\SalesProfile;
use App\Models\Sales\SalesStaffCategoryDefinition;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('does not reactivate Sales access after Staff employment has ended', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $admin->givePermissionTo('sales.profiles.manage', 'sales.profiles.manage-all');
    SalesStaffCategoryDefinition::query()->create([
        'company_id' => $company->id,
        'category_name' => 'sales',
        'status' => 'approved',
        'reason' => 'Approved test category',
        'created_by' => $admin->id,
        'approved_by' => $admin->id,
        'approved_at' => now(),
    ]);
    $staff = Staff::factory()->create([
        'company_id' => $company->id,
        'staff_type' => 'sales',
        'employment_ended_at' => now()->subDay(),
    ]);
    UserContext::create([
        'user_id' => $staff->user_id,
        'context_type' => 'staff',
        'context_id' => $staff->id,
        'is_active' => true,
        'created_user_id' => $admin->id,
    ]);
    $profile = SalesProfile::query()->create([
        'id' => (string) Str::uuid(),
        'company_id' => $company->id,
        'staff_id' => $staff->id,
        'sales_code' => 'FORMER-SALES-01',
        'status' => 'suspended',
        'effective_from' => now()->subMonth(),
        'effective_until' => now()->addMonth(),
        'staff_category_snapshot' => 'sales',
        'reporting_currency' => 'LKR',
        'acquisition_eligible' => true,
        'version' => 1,
    ]);

    actingAs($admin, 'api')->postJson('/api/sales/profiles/'.$profile->id.'/transition', [
        'to_status' => 'active',
        'expected_status' => 'suspended',
        'reason' => 'Attempted reactivation after employment ended',
    ])->assertUnprocessable()->assertJsonPath(
        'message',
        'A Sales Profile can only be reactivated for a current Staff identity with an active User and Staff context.',
    );

    $this->assertDatabaseHas('sales_profiles', ['id' => $profile->id, 'status' => 'suspended']);
});
