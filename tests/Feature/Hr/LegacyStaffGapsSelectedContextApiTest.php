<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('uses the selected Staff identity for global legacy-gap repair metadata', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo('staff.edit-all');
    $secondCompany = Company::create(['name' => 'Second Legacy Repair company']);
    $secondStaff = Staff::factory()->create(['user_id' => $admin->id, 'company_id' => $secondCompany->id]);
    $context = UserContext::create([
        'user_id' => $admin->id, 'context_type' => 'staff', 'context_id' => $secondStaff->id,
        'is_active' => true, 'created_user_id' => $admin->id,
    ]);
    $legacy = Staff::factory()->create(['company_id' => null, 'code' => null]);
    $url = '/api/hr/attendance/legacy-staff-gaps';

    actingAs($admin, 'api')->getJson($url)->assertForbidden();
    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson($url)->assertOk()->assertJsonPath('meta.actor_staff_id', $secondStaff->id)
        ->assertJsonFragment(['id' => $legacy->id]);
});
