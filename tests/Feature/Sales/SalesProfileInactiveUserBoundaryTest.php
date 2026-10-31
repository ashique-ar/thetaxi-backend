<?php

use App\Models\Sales\SalesStaffCategoryDefinition;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('hides and rejects Sales enrollment for an inactive User account', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo('sales.profiles.manage', 'sales.profiles.manage-all');
    SalesStaffCategoryDefinition::query()->create([
        'category_name' => 'sales',
        'status' => 'approved',
        'reason' => 'Approved test category',
        'created_by' => $admin->id,
        'approved_by' => $admin->id,
        'approved_at' => now(),
    ]);
    $staff = Staff::factory()->create(['staff_type' => 'sales']);
    UserContext::create([
        'user_id' => $staff->user_id,
        'context_type' => 'staff',
        'context_id' => $staff->id,
        'is_active' => true,
        'created_user_id' => $admin->id,
    ]);
    $staff->user()->update(['is_active' => false]);

    actingAs($admin, 'api')->getJson('/api/sales/profile-staff-options?selected_id='.$staff->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->postJson('/api/sales/profiles', [
        'staff_id' => $staff->id,
        'sales_code' => 'INACTIVE-STAFF-PROFILE',
        'acquisition_eligible' => true,
        'collection_eligible' => false,
        'commission_eligible' => false,
        'reporting_currency' => 'LKR',
        'effective_from' => now()->subDay()->toDateString(),
    ])->assertUnprocessable()->assertJsonPath('message', 'Sales Profile enrollment requires an active User account.');

    $this->assertDatabaseMissing('sales_profiles', ['staff_id' => $staff->id]);
});
