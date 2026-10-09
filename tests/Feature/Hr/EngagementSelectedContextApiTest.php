<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('limits Engagement audience selectors to the selected Staff company', function () {
    [$admin, $firstCompany] = hr_seed_admin_actor(['name' => 'Selected Engagement company']);
    $admin->givePermissionTo('hr.engagement.manage');
    $firstColleague = Staff::factory()->create(['company_id' => $firstCompany->id, 'code' => 'FIRST-COMPANY-COLLEAGUE']);
    $secondCompany = Company::create(['name' => 'Second Engagement company']);
    $secondColleague = Staff::factory()->create(['company_id' => $secondCompany->id, 'code' => 'SECOND-COMPANY-COLLEAGUE']);
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')->firstOrFail();
    $url = '/api/hr/engagement/audience-options?record_type=staff';

    actingAs($admin, 'api')->getJson($url)->assertOk()->assertJsonCount(2, 'data.data')
        ->assertJsonFragment(['value' => $firstColleague->id])
        ->assertJsonMissing(['value' => $secondColleague->id]);
    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson($url)->assertOk()->assertJsonCount(2, 'data.data')
        ->assertJsonFragment(['value' => $firstColleague->id])
        ->assertJsonMissing(['value' => $secondColleague->id]);
});
