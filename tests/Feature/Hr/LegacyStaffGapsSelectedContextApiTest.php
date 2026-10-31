<?php

use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('uses the selected Staff identity for global legacy-gap repair metadata', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo('staff.edit-all');
    $secondStaff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')->firstOrFail();
    $legacy = Staff::factory()->create(['code' => null]);
    $url = '/api/hr/attendance/legacy-staff-gaps';

    actingAs($admin, 'api')->getJson($url)->assertOk();
    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson($url)->assertOk()->assertJsonPath('meta.actor_staff_id', $secondStaff->id)
        ->assertJsonFragment(['id' => $legacy->id]);
});
