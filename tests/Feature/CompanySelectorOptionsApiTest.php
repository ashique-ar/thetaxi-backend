<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns only active searchable legal entity labels and minimal fields', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo('companies.view');
    $company = Company::create([
        'name' => 'Selector Search Entity',
        'email' => 'private@example.test',
        'phone' => '555-0100',
        'address' => 'Private address',
        'is_active' => true,
    ]);
    Company::create(['name' => 'Selector Search Inactive', 'is_active' => false]);

    $response = actingAs($admin, 'api')->getJson('/api/companies/options?search=Selector%20Search');
    $response->assertOk()->assertJsonPath('status', 'success')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $company->id)
        ->assertJsonPath('data.0.name', 'Selector Search Entity');
    $this->assertEqualsCanonicalizing(['id', 'is_active', 'is_default', 'name'], array_keys($response->json('data.0')));

    actingAs($admin, 'api')->getJson('/api/companies/'.$company->id.'/option')->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.id', $company->id)
        ->assertJsonMissingPath('data.email')
        ->assertJsonMissingPath('data.address');
});

it('requires company and system view permissions for legal entity options', function () {
    hr_seed_admin_actor();
    $companyOnly = User::factory()->create();
    $companyOnly->givePermissionTo('companies.view');
    $systemOnly = User::factory()->create();
    $systemOnly->givePermissionTo('system.view');
    $unprivileged = User::factory()->create();

    actingAs($companyOnly, 'api')->getJson('/api/companies/options')->assertForbidden();
    actingAs($systemOnly, 'api')->getJson('/api/companies/options')->assertForbidden();
    actingAs($unprivileged, 'api')->getJson('/api/companies/options')->assertForbidden();
});
