<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('preserves Staff company on a partial update when the default is another company', function () {
    [$admin, $defaultCompany] = hr_seed_admin_actor(['name' => 'Configured default for Staff updates']);
    $existingCompany = Company::create([
        'name' => 'Existing Staff legal entity',
        'is_active' => true,
        'is_default' => false,
    ]);
    $staff = Staff::factory()->create(['company_id' => $existingCompany->id]);

    actingAs($admin, 'api')->patchJson('/api/staff/'.$staff->id, ['city' => 'Galle'])->assertOk();

    expect($staff->fresh()->company_id)->toBe($existingCompany->id)
        ->and($staff->fresh()->city)->toBe('Galle');

    actingAs($admin, 'api')->patchJson('/api/staff/'.$staff->id, ['company_id' => $defaultCompany->id])->assertOk();

    expect($staff->fresh()->company_id)->toBe($defaultCompany->id);
});

it('requires edit-all to reassign Staff and rejects inactive target companies', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    $company = Company::create(['name' => 'Scoped Staff editor company', 'is_active' => true, 'is_default' => true]);
    $otherCompany = Company::create(['name' => 'Other Staff editor company', 'is_active' => true, 'is_default' => false]);
    $inactiveCompany = Company::create(['name' => 'Inactive Staff editor company', 'is_active' => false, 'is_default' => false]);
    $editor = User::factory()->create();
    $editor->givePermissionTo('staff.edit', 'staff.edit-legal-entity');
    Staff::factory()->create(['user_id' => $editor->id, 'company_id' => $company->id]);
    $target = Staff::factory()->create(['company_id' => $company->id]);

    actingAs($editor, 'api')->patchJson('/api/staff/'.$target->id, ['company_id' => $otherCompany->id])->assertForbidden();
    actingAs($editor, 'api')->patchJson('/api/staff/'.$target->id, ['company_id' => $inactiveCompany->id])->assertUnprocessable();

    expect($target->fresh()->company_id)->toBe($company->id);
});

it('limits explicit Staff creation to the active actor company without create-all', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    $company = Company::create(['name' => 'Scoped Staff creator company', 'is_active' => true, 'is_default' => true]);
    $otherCompany = Company::create(['name' => 'Foreign Staff creator company', 'is_active' => true, 'is_default' => false]);
    $inactiveCompany = Company::create(['name' => 'Inactive Staff creator company', 'is_active' => false, 'is_default' => false]);
    $creator = User::factory()->create();
    $creator->givePermissionTo('staff.create');
    Staff::factory()->create(['user_id' => $creator->id, 'company_id' => $company->id]);

    actingAs($creator, 'api')->postJson('/api/staff', ['phone' => '0771112222', 'company_id' => $otherCompany->id])->assertForbidden();
    actingAs($creator, 'api')->postJson('/api/staff', ['phone' => '0771112222', 'company_id' => $inactiveCompany->id])->assertUnprocessable();

    expect(Staff::query()->count())->toBe(1);
});

it('fails closed when Staff creation has neither an active default nor an explicit company', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    Company::create(['name' => 'Non-default Staff creator company', 'is_active' => true, 'is_default' => false]);
    $creator = User::factory()->create();
    $creator->givePermissionTo('staff.create', 'staff.create-all');

    actingAs($creator, 'api')->postJson('/api/staff', ['phone' => '0771112222'])->assertUnprocessable();

    expect(Staff::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(1);
});
