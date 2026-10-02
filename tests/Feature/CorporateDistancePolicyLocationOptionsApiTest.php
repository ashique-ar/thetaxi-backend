<?php

use App\Models\Corporate\Corporate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and hydrates only active operator or same-corporate contractual locations', function () {
    [$admin] = hr_seed_admin_actor();
    $corporate = Corporate::create(['name' => 'Selector Corporate']);
    $foreignCorporate = Corporate::create(['name' => 'Foreign Selector Corporate']);
    $operatorId = (string) Str::uuid();
    $locationId = (string) Str::uuid();
    $inactiveId = (string) Str::uuid();
    $foreignId = (string) Str::uuid();
    DB::table('corporate_contract_locations')->insert([
        ['id' => $operatorId, 'corporate_id' => null, 'owner_type' => 'operator', 'name' => 'Operator Base', 'address' => 'Colombo', 'latitude' => 6.9, 'longitude' => 79.8, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => $locationId, 'corporate_id' => $corporate->id, 'owner_type' => 'customer', 'name' => 'Customer Depot', 'address' => 'Kandy', 'latitude' => 7.2, 'longitude' => 80.6, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => $inactiveId, 'corporate_id' => $corporate->id, 'owner_type' => 'named_contract', 'name' => 'Old Depot', 'address' => 'Galle', 'latitude' => 6.0, 'longitude' => 80.2, 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
        ['id' => $foreignId, 'corporate_id' => $foreignCorporate->id, 'owner_type' => 'customer', 'name' => 'Foreign Depot', 'address' => 'Jaffna', 'latitude' => 9.7, 'longitude' => 80.0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $url = '/api/admin/corporates/'.$corporate->id.'/distance-pricing-policy/location-options';

    actingAs($admin, 'api')->getJson($url.'?record_type=operator_location&search=Operator')
        ->assertOk()->assertJsonPath('data.data.0.value', $operatorId)->assertJsonPath('data.data.0.label', 'Operator Base');
    actingAs($admin, 'api')->getJson($url.'?record_type=corporate_location&search=Depot')
        ->assertOk()->assertJsonPath('data.data.0.value', $locationId)->assertJsonCount(1, 'data.data');
    actingAs($admin, 'api')->getJson($url.'?record_type=corporate_location&selected_id='.$inactiveId)
        ->assertOk()->assertJsonPath('data.data.0.value', $inactiveId)->assertJsonPath('data.data.0.status', 'inactive');
    actingAs($admin, 'api')->getJson($url.'?record_type=corporate_location&selected_id='.$foreignId)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'?record_type=corporate_location&per_page=51')->assertUnprocessable();
});
