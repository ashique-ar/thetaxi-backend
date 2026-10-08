<?php

use App\Models\Company;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns the default company only when it is in the authorized performance scope', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id,
        'sales_code' => 'DEFAULT-PERF-01', 'status' => 'active', 'effective_from' => now()->subDay(),
    ]);

    actingAs($admin, 'api')->getJson('/api/sales/performance/administration-context')
        ->assertOk()->assertJsonPath('data.default_company_id', $company->id)
        ->assertJsonPath('data.companyLabels.0.id', $company->id);
});

it('uses the default company when performance filters omit company_id', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id,
        'sales_code' => 'DEFAULT-PERF-FILTER-01', 'status' => 'active', 'effective_from' => now()->subDay(),
    ]);

    actingAs($admin, 'api')->getJson('/api/sales/performance/targets')
        ->assertOk()->assertJsonPath('data.data', []);
    actingAs($admin, 'api')->getJson('/api/sales/performance/snapshots')
        ->assertOk()->assertJsonPath('data.data', []);
});

it('does not offer a default company outside the authorized performance scope', function () {
    [$admin] = hr_seed_admin_actor();
    $authorizedCompany = Company::create(['name' => 'Authorized non-default Sales company']);
    $staff = Staff::factory()->create(['company_id' => $authorizedCompany->id]);
    SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $authorizedCompany->id, 'staff_id' => $staff->id,
        'sales_code' => 'AUTHORIZED-PERF-01', 'status' => 'active', 'effective_from' => now()->subDay(),
    ]);

    actingAs($admin, 'api')->getJson('/api/sales/performance/administration-context')
        ->assertOk()->assertJsonPath('data.default_company_id', null)
        ->assertJsonPath('data.companyLabels.0.id', $authorizedCompany->id);
});

it('does not preselect an inactive default company', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id,
        'sales_code' => 'INACTIVE-PERF-01', 'status' => 'active', 'effective_from' => now()->subDay(),
    ]);
    DB::table('companies')->where('id', $company->id)->update(['is_active' => false]);

    actingAs($admin, 'api')->getJson('/api/sales/performance/administration-context')
        ->assertOk()->assertJsonPath('data.default_company_id', null)
        ->assertJsonPath('data.companyLabels.0.id', $company->id)
        ->assertJsonPath('data.companyLabels.0.is_active', false);
});

it('lists active performance companies before an inactive configured default', function () {
    [$admin, $defaultCompany] = hr_seed_admin_actor();
    $defaultStaff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $defaultCompany->id, 'staff_id' => $defaultStaff->id,
        'sales_code' => 'INACTIVE-OPTION-01', 'status' => 'active', 'effective_from' => now()->subDay(),
    ]);
    DB::table('companies')->where('id', $defaultCompany->id)->update(['is_active' => false]);

    $activeCompany = Company::create(['name' => 'Active non-default Sales company']);
    $activeStaff = Staff::factory()->create(['company_id' => $activeCompany->id]);
    SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $activeCompany->id, 'staff_id' => $activeStaff->id,
        'sales_code' => 'ACTIVE-OPTION-01', 'status' => 'active', 'effective_from' => now()->subDay(),
    ]);

    actingAs($admin, 'api')->getJson('/api/sales/performance/company-options?per_page=1')
        ->assertOk()->assertJsonPath('data.data.0.value', $activeCompany->id)
        ->assertJsonPath('default_company_id', null);
});
