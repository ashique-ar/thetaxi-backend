<?php

use App\Models\Company;
use App\Services\SingleCompanyScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves the configured active default when other active companies exist', function () {
    Company::create(['name' => 'Other active company', 'is_active' => true, 'is_default' => false]);
    $default = Company::create(['name' => 'Configured default company', 'is_active' => true, 'is_default' => true]);

    expect(app(SingleCompanyScope::class)->defaultCompany()?->id)->toBe($default->id);
});

it('does not resolve an inactive default company', function () {
    Company::create(['name' => 'Inactive default company', 'is_active' => false, 'is_default' => true]);

    expect(app(SingleCompanyScope::class)->defaultCompany())->toBeNull();
});
