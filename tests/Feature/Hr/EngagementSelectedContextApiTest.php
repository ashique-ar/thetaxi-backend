<?php

use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('shows Engagement audience selectors across the installation', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.engagement.manage');
    $firstColleague = Staff::factory()->create(['code' => 'FIRST-INSTALLATION-COLLEAGUE']);
    $secondColleague = Staff::factory()->create(['code' => 'SECOND-INSTALLATION-COLLEAGUE']);
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')->firstOrFail();
    $url = '/api/hr/engagement/audience-options?record_type=staff';

    actingAs($admin, 'api')->getJson($url)->assertOk()->assertJsonCount(3, 'data.data')
        ->assertJsonFragment(['value' => $firstColleague->id])
        ->assertJsonFragment(['value' => $secondColleague->id]);
    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson($url)->assertOk()->assertJsonCount(3, 'data.data')
        ->assertJsonFragment(['value' => $firstColleague->id])
        ->assertJsonFragment(['value' => $secondColleague->id]);
});
