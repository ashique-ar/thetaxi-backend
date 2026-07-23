<?php

use App\Models\Vehicle\VehicleAddon;
use App\Http\Middleware\PermissionMiddleware;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('provides the category CRUD contract used by the active add-on form', function (): void {
    $this->withoutMiddleware([Authenticate::class, PermissionMiddleware::class]);

    $created = $this->postJson('/api/vehicles/vehicle-addon-categories', [
        'name' => 'Passenger comfort',
        'description' => 'Optional passenger comfort equipment.',
        'icon' => 'airline_seat_recline_extra',
        'sort_order' => 2,
        'is_active' => true,
    ])->assertCreated()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.name', 'Passenger comfort');

    $categoryId = $created->json('data.id');

    $this->getJson('/api/vehicles/vehicle-addon-categories')
        ->assertOk()
        ->assertJsonPath('data.0.id', $categoryId)
        ->assertJsonPath('data.0.addons_count', 0);

    $this->putJson("/api/vehicles/vehicle-addon-categories/{$categoryId}", [
        'name' => 'Passenger essentials',
        'is_active' => false,
    ])->assertOk()
        ->assertJsonPath('data.name', 'Passenger essentials')
        ->assertJsonPath('data.is_active', false);

    VehicleAddon::create([
        'category_id' => $categoryId,
        'name' => 'Child seat',
        'addon_type' => 'item',
        'pricing_type' => 'fixed',
        'quantity_unit' => 'pieces',
        'amount' => 500,
    ]);

    $this->deleteJson("/api/vehicles/vehicle-addon-categories/{$categoryId}")
        ->assertStatus(422);
});
