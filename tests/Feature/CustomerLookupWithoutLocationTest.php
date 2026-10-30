<?php

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches installation customers without a selected company location', function () {
    $user = User::factory()->create();
    $customerUser = User::factory()->create(['first_name' => 'Shared', 'last_name' => 'Customer']);
    $customer = Customer::create(['user_id' => $customerUser->id, 'code' => 'CUS-SHARED']);

    actingAs($user, 'api')
        ->getJson('/api/customers/search?q=Shared')
        ->assertOk()
        ->assertJsonPath('data.0.id', $customer->id);
});
