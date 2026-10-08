<?php

use App\Models\Company;
use App\Models\Hr\HrEmploymentAssignment;
use App\Models\Hr\HrEmploymentSpell;
use App\Models\Hr\Attendance\AttendanceConnector;
use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
use App\Services\BookingFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('uses the active default company even when it has no distance coordinates', function () {
    $company = Company::create([
        'name' => 'Default distance company',
        'is_active' => true,
        'is_default' => true,
    ]);

    expect(Company::getDefaultCompany()?->id)->toBe($company->id);

    $company->update(['is_active' => false]);

    expect(Company::getDefaultCompany())->toBeNull();
});

it('does not invent a Colombo company location when no company has map coordinates', function () {
    Company::create([
        'name' => 'Configured default without map coordinates',
        'is_active' => true,
        'is_default' => true,
    ]);

    $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();

    expect($service->getCompanyLocations())->toBe([]);
});

it('does not treat another mapped company as the default when the configured default lacks coordinates', function () {
    Company::create([
        'name' => 'Configured default without map coordinates',
        'is_active' => true,
        'is_default' => true,
    ]);
    Company::create([
        'name' => 'Other mapped company',
        'address' => '1 Example Road',
        'latitude' => 6.9,
        'longitude' => 79.8,
        'is_active' => true,
        'is_default' => false,
    ]);

    $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
    $locations = $service->getCompanyLocations();

    expect($locations)->toHaveCount(1)
        ->and($locations[0]['name'])->toBe('Other mapped company')
        ->and($locations[0]['is_default'])->toBeFalse();
});

it('defaults HR fixtures to the configured company instead of the first company', function () {
    Company::create(['name' => 'First non-default company', 'is_active' => true, 'is_default' => false]);
    $default = Company::create(['name' => 'Configured default company', 'is_active' => true, 'is_default' => true]);

    foreach ([
        Staff::factory(),
        HrEmploymentSpell::factory(),
        HrEmploymentAssignment::factory(),
        AttendanceDevice::factory(),
        AttendanceConnector::factory(),
    ] as $factory) {
        expect($factory->make()->company_id)->toBe($default->id);
    }
});

it('persists the earliest active company as default when none is configured', function () {
    $first = Company::create(['name' => 'First non-default company', 'is_active' => true, 'is_default' => false]);
    $second = Company::create(['name' => 'Second non-default company', 'is_active' => true, 'is_default' => false]);
    DB::table('companies')->where('id', $first->id)->update(['created_at' => now()->subMinute()]);
    DB::table('companies')->where('id', $second->id)->update(['created_at' => now()]);

    foreach ([
        Staff::factory(),
        HrEmploymentSpell::factory(),
        HrEmploymentAssignment::factory(),
        AttendanceDevice::factory(),
        AttendanceConnector::factory(),
    ] as $factory) {
        $companyId = $factory->make()->company_id;
        expect($companyId)->toBe($first->id);
    }

    $this->assertDatabaseHas('companies', ['id' => $first->id, 'is_default' => true]);
    $this->assertDatabaseHas('companies', ['id' => $second->id, 'is_default' => false]);
});
