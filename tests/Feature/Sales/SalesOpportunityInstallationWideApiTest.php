<?php

use App\Models\Sales\SalesOpportunity;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('lists installation-wide opportunities while preserving Sales permissions', function () {
    [$actor] = hr_seed_admin_actor();
    $actor->givePermissionTo(Permission::findByName('sales.crm.view', 'api'));
    $actor->givePermissionTo(Permission::findByName('sales.crm.view-all', 'api'));

    $makeOpportunity = function (string $code) use ($actor): SalesOpportunity {
        $staff = Staff::factory()->create();
        $profile = SalesProfile::query()->create([
            'staff_id' => $staff->id, 'sales_code' => $code,
            'status' => 'active', 'effective_from' => now()->subDay(), 'staff_category_snapshot' => 'Sales',
            'reporting_currency' => 'LKR', 'acquisition_eligible' => true, 'created_user_id' => $actor->id,
        ]);
        return SalesOpportunity::query()->create([
            'owner_sales_profile_id' => $profile->id,
            'opportunity_number' => $code.'-OPP', 'name' => $code.' opportunity', 'source' => 'manual',
            'created_user_id' => $actor->id,
        ]);
    };
    $makeOpportunity('FIRST');
    $makeOpportunity('SECOND');

    actingAs($actor, 'api')->getJson('/api/sales/opportunity-company-options')->assertNotFound();
    actingAs($actor, 'api')->getJson('/api/sales/opportunities')->assertOk()
        ->assertJsonCount(2, 'data.data')
        ->assertJsonFragment(['opportunity_number' => 'FIRST-OPP'])
        ->assertJsonFragment(['opportunity_number' => 'SECOND-OPP']);
    actingAs($actor, 'api')->getJson('/api/sales/opportunities?company_id=00000000-0000-0000-0000-000000000001')
        ->assertOk()->assertJsonCount(2, 'data.data')
        ->assertJsonFragment(['opportunity_number' => 'FIRST-OPP'])
        ->assertJsonFragment(['opportunity_number' => 'SECOND-OPP']);

    $limitedUser = User::factory()->create();
    $limitedUser->givePermissionTo(Permission::findByName('sales.crm.view', 'api'));
    $limitedStaff = Staff::factory()->create(['user_id' => $limitedUser->id]);
    UserContext::create([
        'user_id' => $limitedUser->id, 'context_type' => 'staff', 'context_id' => $limitedStaff->id,
        'is_active' => true, 'created_user_id' => $actor->id,
    ]);
    $limitedProfile = SalesProfile::query()->create([
        'staff_id' => $limitedStaff->id, 'sales_code' => 'LIMITED-CRM',
        'status' => 'active', 'effective_from' => now()->subDay(), 'staff_category_snapshot' => 'Sales',
        'reporting_currency' => 'LKR', 'acquisition_eligible' => true, 'created_user_id' => $actor->id,
    ]);
    SalesOpportunity::query()->create([
        'owner_sales_profile_id' => $limitedProfile->id,
        'opportunity_number' => 'LIMITED-OPP', 'name' => 'Limited opportunity', 'source' => 'manual',
        'created_user_id' => $actor->id,
    ]);
    actingAs($limitedUser, 'api')->getJson('/api/sales/opportunities')->assertOk()
        ->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.opportunity_number', 'LIMITED-OPP');
});
