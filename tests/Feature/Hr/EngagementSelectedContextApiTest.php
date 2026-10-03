<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('limits Engagement audience selectors to the selected Staff company', function () {
    [$admin, $firstCompany] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.engagement.manage');
    $firstColleague = Staff::factory()->create(['company_id' => $firstCompany->id, 'code' => 'FIRST-COMPANY-COLLEAGUE']);
    $secondCompany = Company::create(['name' => 'Second Engagement company']);
    $secondStaff = Staff::factory()->create(['user_id' => $admin->id, 'company_id' => $secondCompany->id]);
    $secondColleague = Staff::factory()->create(['company_id' => $secondCompany->id, 'code' => 'SECOND-COMPANY-COLLEAGUE']);
    $context = UserContext::create([
        'user_id' => $admin->id, 'context_type' => 'staff', 'context_id' => $secondStaff->id,
        'is_active' => true, 'created_user_id' => $admin->id,
    ]);
    $url = '/api/hr/engagement/audience-options?record_type=staff';

    actingAs($admin, 'api')->getJson($url)->assertForbidden();
    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson($url)->assertOk()->assertJsonCount(2, 'data.data')
        ->assertJsonFragment(['value' => $secondStaff->id])
        ->assertJsonFragment(['value' => $secondColleague->id])
        ->assertJsonMissing(['value' => $firstColleague->id]);
});
