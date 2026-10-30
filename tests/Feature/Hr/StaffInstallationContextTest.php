<?php

use App\Models\Staff;
use App\Models\User;
use App\Services\Hr\EmployeeNumberAllocator;
use App\Services\Hr\PeopleAccessService;
use App\Services\UserContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates a Staff context without a selected or default location', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    $user = User::factory()->create();

    $context = app(UserContextService::class)->switchContext($user, 'staff');
    $staff = Staff::query()->findOrFail($context->context_id);

    expect($staff->getAttributes())->not->toHaveKey('company_id');
});

it('allows HR access by role when the active staff identity has no company ID', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    $user = User::factory()->create();
    $user->givePermissionTo('hr.people.view-all');
    $context = app(UserContextService::class)->switchContext($user, 'staff');
    $staff = Staff::query()->findOrFail($context->context_id);

    expect(app(PeopleAccessService::class)->scope(Staff::query(), $user)->whereKey($staff->id)->exists())->toBeTrue();
});

it('allocates employee numbers from one installation-wide sequence', function () {
    $actor = User::factory()->create();
    $staff = Staff::factory()->create(['code' => null]);

    expect(app(EmployeeNumberAllocator::class)->allocate($staff, (string) $actor->id))->toBe('EMP-1001');
    $this->assertDatabaseHas('hr_employee_number_sequences', ['installation_key' => 'installation']);
    expect(Schema::hasColumn('hr_employee_number_sequences', 'company_id'))->toBeFalse()
        ->and(Schema::hasColumn('hr_employee_number_aliases', 'company_id'))->toBeFalse();
});
