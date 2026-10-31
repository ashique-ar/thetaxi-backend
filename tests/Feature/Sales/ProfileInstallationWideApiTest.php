<?php

use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns Sales Profile administration readiness without a company selector', function () {
    [$admin] = hr_seed_admin_actor(['name' => 'Profile Administration']);
    $staff = Staff::factory()->create();
    UserContext::create([
        'user_id' => $staff->user_id,
        'context_type' => 'staff',
        'context_id' => $staff->id,
        'is_active' => true,
        'created_user_id' => $admin->id,
    ]);
    $staff->user->givePermissionTo('sales.profiles.view');

    actingAs($staff->user, 'api')->getJson('/api/sales/profile-administration-context')
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'sales_staff_categories',
            'sales_staff_category_ready',
            'profile_export_ready',
            'can_manage_profiles',
            'can_export_profiles',
        ]])
        ->assertJsonMissingPath('data.default_company_id');
});
