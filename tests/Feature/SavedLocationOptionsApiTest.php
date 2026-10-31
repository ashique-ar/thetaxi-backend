<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns active searchable saved locations without defaults or contact data', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo('companies.view');
    $location = Company::create([
        'name' => 'Selector Search Location',
        'email' => 'private@example.test',
        'phone' => '555-0100',
        'address' => 'Private address',
        'is_active' => true,
    ]);
    Company::create(['name' => 'Selector Search Inactive', 'is_active' => false]);

    $response = actingAs($admin, 'api')->getJson('/api/companies/options?search=Selector%20Search');
    $response->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $location->id)
        ->assertJsonPath('data.0.name', 'Selector Search Location')
        ->assertJsonMissingPath('default_company_id')
        ->assertJsonMissingPath('data.0.is_default');
    $this->assertEqualsCanonicalizing(['id', 'is_active', 'name'], array_keys($response->json('data.0')));

    actingAs($admin, 'api')->getJson('/api/companies/'.$location->id.'/option')->assertOk()
        ->assertJsonPath('data.id', $location->id)
        ->assertJsonMissingPath('data.email')
        ->assertJsonMissingPath('data.address')
        ->assertJsonMissingPath('data.is_default');
});

it('requires location-management permission to read saved-location options', function () {
    hr_seed_admin_actor();
    $locationsViewer = User::factory()->create();
    $locationsViewer->givePermissionTo('companies.view');
    $systemOnly = User::factory()->create();
    $systemOnly->givePermissionTo('system.view');
    $unprivileged = User::factory()->create();

    actingAs($locationsViewer, 'api')->getJson('/api/companies/options')->assertForbidden();
    actingAs($systemOnly, 'api')->getJson('/api/companies/options')->assertForbidden();
    actingAs($unprivileged, 'api')->getJson('/api/companies/options')->assertForbidden();
});

it('creates a saved location without restoring the removed default marker', function () {
    (new \Database\Seeders\AllPermissionsSeeder())->run();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $response = actingAs($admin, 'api')->postJson('/api/companies', [
        'name' => 'First saved location',
        'is_active' => null,
    ])->assertCreated()
        ->assertJsonPath('data.is_active', true)
        ->assertJsonMissingPath('data.is_default');

    $this->assertDatabaseHas('companies', [
        'id' => $response->json('data.id'),
        'is_active' => true,
    ]);
});
