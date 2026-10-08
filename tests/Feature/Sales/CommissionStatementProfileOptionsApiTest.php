<?php

use App\Models\Company;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and hydrates only active commission-eligible profiles in the requested company', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Statement Options Company']);
    $admin->givePermissionTo(['sales.commission-statements.generate', 'sales.commission-statements.view-all']);
    $makeProfile = function (string $companyId, string $code, array $overrides = []) {
        $staff = Staff::factory()->create(['company_id' => $companyId, 'code' => $code.'-STAFF']);
        $staff->user->update(['first_name' => 'Commission', 'last_name' => 'Seller']);
        return SalesProfile::query()->create(array_merge([
            'id' => (string) Str::uuid(), 'company_id' => $companyId, 'staff_id' => $staff->id,
            'sales_code' => $code, 'status' => 'active', 'effective_from' => now()->subDay(),
            'staff_category_snapshot' => 'sales', 'reporting_currency' => 'LKR', 'commission_eligible' => true,
        ], $overrides));
    };
    $target = $makeProfile($company->id, 'STATEMENT-TARGET');
    $ineligible = $makeProfile($company->id, 'STATEMENT-INELIGIBLE', ['commission_eligible' => false]);
    $inactive = $makeProfile($company->id, 'STATEMENT-INACTIVE', ['status' => 'inactive']);
    $formerStaff = Staff::factory()->former()->create(['company_id' => $company->id]);
    $former = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $formerStaff->id,
        'sales_code' => 'STATEMENT-FORMER', 'status' => 'active', 'effective_from' => now()->subDay(),
        'staff_category_snapshot' => 'sales', 'reporting_currency' => 'LKR', 'commission_eligible' => true,
    ]);
    $otherCompany = Company::create(['name' => 'Other Statement Company']);
    $foreign = $makeProfile($otherCompany->id, 'STATEMENT-FOREIGN');
    $mismatchedStaff = Staff::factory()->create(['company_id' => $otherCompany->id]);
    $mismatched = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $mismatchedStaff->id,
        'sales_code' => 'STATEMENT-MISMATCHED-STAFF', 'status' => 'active', 'effective_from' => now()->subDay(),
        'staff_category_snapshot' => 'sales', 'reporting_currency' => 'LKR', 'commission_eligible' => true,
    ]);
    $url = '/api/sales/commission-statement-profile-options?company_id='.$company->id;

    actingAs($admin, 'api')->getJson($url.'&search=STATEMENT-TARGET&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $target->id)
        ->assertJsonPath('data.data.0.label', 'STATEMENT-TARGET — Commission Seller')
        ->assertJsonPath('data.data.0.metadata.company', 'Statement Options Company');
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$target->id.'&search=no-match')
        ->assertOk()->assertJsonPath('data.data.0.value', $target->id);
    actingAs($admin, 'api')->getJson('/api/sales/commission-statement-profile-options?selected_id='.$target->id)
        ->assertOk()->assertJsonPath('data.data.0.value', $target->id);
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$foreign->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$mismatched->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson('/api/sales/commission-statement-schedule?company_id='.$company->id.'&sales_profile_id='.$mismatched->id.'&reference_date=2026-09-30')
        ->assertNotFound();
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$ineligible->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$inactive->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$former->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    $payload = [
        'company_id' => $company->id, 'sales_profile_id' => $mismatched->id,
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        'cutoff_at' => '2026-10-01T00:00:00Z',
    ];
    actingAs($admin, 'api')->postJson('/api/sales/commission-statements/preview', $payload)->assertNotFound();
    actingAs($admin, 'api')->postJson('/api/sales/commission-statements/generate', $payload + [
        'idempotency_key' => (string) Str::uuid(),
    ])->assertNotFound();
    actingAs($admin, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
});
