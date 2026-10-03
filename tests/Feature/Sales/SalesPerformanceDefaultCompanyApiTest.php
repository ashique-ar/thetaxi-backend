<?php

use App\Models\Company;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
