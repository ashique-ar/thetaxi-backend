<?php

use App\Models\Company;
use App\Services\Hr\StaffDefaultCompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses the active configured company when Staff has no company selection', function () {
    $default = Company::create(['name' => 'Active Staff Default', 'is_active' => true, 'is_default' => true]);

    expect(app(StaffDefaultCompanyService::class)->apply([]))
        ->toMatchArray(['company_id' => $default->id]);
});

it('preserves an explicit Staff company and ignores an inactive configured default', function () {
    $default = Company::create(['name' => 'Inactive Staff Default', 'is_active' => false, 'is_default' => true]);
    $explicit = Company::create(['name' => 'Explicit Staff Company', 'is_active' => true, 'is_default' => false]);
    $service = app(StaffDefaultCompanyService::class);

    expect($service->id())->toBeNull()
        ->and($service->apply(['company_id' => $explicit->id]))
        ->toMatchArray(['company_id' => $explicit->id]);
});
