<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns only active searchable legal entity labels and minimal fields', function () {
    [$admin, $default] = hr_seed_admin_actor();
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
        ->assertJsonPath('data.0.name', 'Selector Search Entity')->assertJsonPath('default_company_id', $default->id);
    $this->assertEqualsCanonicalizing(['id', 'is_active', 'is_default', 'name'], array_keys($response->json('data.0')));

    actingAs($admin, 'api')->getJson('/api/companies/'.$company->id.'/option')->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.id', $company->id)
        ->assertJsonMissingPath('data.email')
        ->assertJsonMissingPath('data.address');
});

it('keeps one canonical default when the database rejects a second default', function () {
    [$admin, $default] = hr_seed_admin_actor();
    $admin->givePermissionTo('companies.view');
    $other = Company::create(['name' => 'Second active entity', 'is_active' => true]);
    expect(fn () => DB::table('companies')->where('id', $other->id)->update(['is_default' => true]))
        ->toThrow(\Illuminate\Database\QueryException::class);

    actingAs($admin, 'api')->getJson('/api/companies/options')
        ->assertOk()->assertJsonPath('default_company_id', $default->id);
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

it('does not allow an inactive company to become the default entity', function () {
    [$admin, $default] = hr_seed_admin_actor();
    $inactive = Company::create([
        'name' => 'Inactive non-default entity',
        'is_active' => false,
        'is_default' => false,
    ]);

    actingAs($admin, 'api')->putJson('/api/companies/'.$inactive->id, ['is_default' => true])
        ->assertNotFound();

    $this->assertDatabaseHas('companies', [
        'id' => $default->id,
        'is_default' => true,
        'is_active' => true,
    ]);
    $this->assertDatabaseHas('companies', [
        'id' => $inactive->id,
        'is_default' => false,
        'is_active' => false,
    ]);
});

it('normalizes a null active flag when creating a company', function () {
    [$admin] = hr_seed_admin_actor();

    $response = actingAs($admin, 'api')->postJson('/api/companies', [
        'name' => 'Company with omitted active state',
        'is_active' => null,
    ])->assertCreated()->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.is_default', false);

    $this->assertDatabaseHas('companies', [
        'id' => $response->json('data.id'),
        'is_active' => true,
        'is_default' => false,
    ]);
});

it('does not infer the first active company as default when the form flag is false', function () {
    (new \Database\Seeders\AllPermissionsSeeder())->run();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $response = actingAs($admin, 'api')->postJson('/api/companies', [
        'name' => 'First active company',
        'is_active' => true,
        'is_default' => false,
    ])->assertCreated()->assertJsonPath('data.is_default', false);

    $this->assertDatabaseHas('companies', [
        'id' => $response->json('data.id'),
        'is_active' => true,
        'is_default' => false,
    ]);
    actingAs($admin, 'api')->getJson('/api/companies/options')
        ->assertOk()->assertJsonPath('default_company_id', null);

    $selected = actingAs($admin, 'api')->postJson('/api/companies', [
        'name' => 'Explicit default company',
        'is_active' => true,
        'is_default' => true,
    ])->assertCreated()->assertJsonPath('data.is_default', true);
    $this->assertDatabaseHas('companies', [
        'id' => $response->json('data.id'),
        'is_default' => false,
    ]);
    actingAs($admin, 'api')->getJson('/api/companies/options')
        ->assertOk()->assertJsonPath('default_company_id', $selected->json('data.id'));
});

it('preserves the active default when an update sends a null active flag', function () {
    [$admin, $default] = hr_seed_admin_actor();

    actingAs($admin, 'api')->putJson('/api/companies/'.$default->id, ['is_active' => null])
        ->assertOk()->assertJsonPath('data.is_active', true);
});

it('does not delete the configured default entity', function () {
    [$admin, $default] = hr_seed_admin_actor();

    actingAs($admin, 'api')->deleteJson('/api/companies/'.$default->id)->assertUnprocessable();

    $this->assertDatabaseHas('companies', [
        'id' => $default->id,
        'is_default' => true,
        'deleted_at' => null,
    ]);
});
