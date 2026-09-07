<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and exactly hydrates only legal entities in the actor scope', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Scoped Finality Company', 'city' => 'Colombo']);
    $actor = Staff::factory()->create(['company_id' => $company->id]);
    UserContext::create([
        'user_id' => $actor->user_id, 'context_type' => 'staff', 'context_id' => $actor->id,
        'is_active' => true, 'created_user_id' => $admin->id,
    ]);
    $actor->user->givePermissionTo('sales.payment-finality.manage');
    $foreign = Company::create(['name' => 'Foreign Finality Company', 'city' => 'Kandy']);
    $url = '/api/sales/payment-finality-company-options';

    $response = actingAs($actor->user, 'api')->getJson($url.'?search=Scoped&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $company->id)
        ->assertJsonPath('data.data.0.label', 'Scoped Finality Company')
        ->assertJsonPath('data.data.0.metadata.city', 'Colombo');
    expect(array_keys($response->json('data.data.0')))->toBe(['value', 'label', 'metadata', 'status']);
    actingAs($actor->user, 'api')->getJson($url.'?selected_id='.$company->id.'&search=no-match')
        ->assertOk()->assertJsonPath('data.data.0.value', $company->id);
    actingAs($actor->user, 'api')->getJson($url.'?selected_id='.$foreign->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson($url.'?per_page=51')->assertUnprocessable();
    actingAs($actor->user, 'api')->getJson($url.'?page=0')->assertUnprocessable();
});

it('does not hydrate a deleted legal entity for a new policy', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $company->delete();
    actingAs($admin, 'api')->getJson('/api/sales/payment-finality-company-options?selected_id='.$company->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
});
