<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use App\Services\Hr\StaffDefaultCompanyService;
use App\Services\UserContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('uses the active configured company when Staff has no company selection', function () {
    $default = Company::create(['name' => 'Active Staff Default', 'is_active' => true, 'is_default' => true]);

    expect(app(StaffDefaultCompanyService::class)->apply([]))
        ->toMatchArray(['company_id' => $default->id]);
});

it('preserves an explicit Staff company and bootstraps it when the configured default is inactive', function () {
    $default = Company::create(['name' => 'Inactive Staff Default', 'is_active' => false, 'is_default' => true]);
    $explicit = Company::create(['name' => 'Explicit Staff Company', 'is_active' => true, 'is_default' => false]);
    $service = app(StaffDefaultCompanyService::class);

    expect($service->id())->toBe($explicit->id)
        ->and($service->apply(['company_id' => $explicit->id]))
        ->toMatchArray(['company_id' => $explicit->id]);
});

it('creates a Staff context under the canonical active default company', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    $default = Company::create(['name' => 'Staff Context Default', 'is_active' => true, 'is_default' => true]);
    $user = User::factory()->create();

    $context = app(UserContextService::class)->switchContext($user, 'staff');

    expect(Staff::query()->findOrFail($context->context_id)->company_id)->toBe($default->id);
});

it('fails closed when creating a Staff context without an active default company', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    Company::create(['name' => 'Inactive Staff Context Default', 'is_active' => false, 'is_default' => true]);
    $user = User::factory()->create();

    expect(fn () => app(UserContextService::class)->switchContext($user, 'staff'))
        ->toThrow(HttpException::class);
    expect(Staff::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('rechecks the locked active company before creating People Core employment history', function () {
    $company = Company::create(['name' => 'Inactive People Core company', 'is_active' => false, 'is_default' => false]);
    $actor = User::factory()->create();
    $staff = Staff::factory()->create(['company_id' => $company->id, 'code' => null]);
    config(['hr.features.people_core' => true]);

    expect(fn () => app(\App\Services\Hr\PeopleCoreService::class)->initializeStaff($staff, [], (string) $actor->id))
        ->toThrow(HttpException::class);
    $this->assertDatabaseMissing('hr_employee_number_sequences', ['company_id' => $company->id]);
    $this->assertDatabaseMissing('hr_employment_spells', ['staff_id' => $staff->id]);
});
