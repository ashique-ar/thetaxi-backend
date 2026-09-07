<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and hydrates only non-deleted companies belonging to an active Staff identity', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Scoped Commission Company', 'city' => 'Colombo']);
    $actor = Staff::factory()->create(['company_id' => $company->id]);
    UserContext::create(['user_id' => $actor->user_id, 'context_type' => 'staff', 'context_id' => $actor->id,
        'is_active' => true, 'created_user_id' => $admin->id]);
    $actor->user->givePermissionTo('sales.commission-config.view');
    $foreign = Company::create(['name' => 'Foreign Commission Company']);
    $deleted = Company::create(['name' => 'Deleted Commission Company', 'deleted_at' => now()]);
    $url = '/api/sales/commission-configuration/company-options';

    $response = actingAs($actor->user, 'api')->getJson($url.'?search=Scoped&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $company->id)->assertJsonPath('data.data.0.label', 'Scoped Commission Company');
    expect(array_keys($response->json('data.data.0')))->toBe(['value', 'label', 'metadata', 'status']);
    actingAs($actor->user, 'api')->getJson($url.'?selected_id='.$company->id.'&search=no-match')
        ->assertOk()->assertJsonPath('data.data.0.value', $company->id);
    actingAs($actor->user, 'api')->getJson($url.'?selected_id='.$foreign->id)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson($url.'?selected_id='.$deleted->id)->assertOk()->assertJsonCount(0, 'data.data');
    $actor->update(['employment_ended_at' => now()]);
    actingAs($actor->user, 'api')->getJson($url.'?selected_id='.$company->id)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson($url.'?per_page=51')->assertUnprocessable();
});
