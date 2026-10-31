<?php

use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches active commission-eligible profiles across locations', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo(['sales.commission-statements.generate', 'sales.commission-statements.view-all']);
    $makeProfile = function (string $code, array $overrides = []) {
        $staff = Staff::factory()->create(['code' => $code.'-STAFF']);
        $staff->user->update(['first_name' => 'Commission', 'last_name' => 'Seller']);
        return SalesProfile::query()->create(array_merge([
            'id' => (string) Str::uuid(), 'staff_id' => $staff->id,
            'sales_code' => $code, 'status' => 'active', 'effective_from' => now()->subDay(),
            'staff_category_snapshot' => 'sales', 'reporting_currency' => 'LKR', 'commission_eligible' => true,
        ], $overrides));
    };
    $target = $makeProfile('STATEMENT-TARGET');
    $ineligible = $makeProfile('STATEMENT-INELIGIBLE', ['commission_eligible' => false]);
    $inactive = $makeProfile('STATEMENT-INACTIVE', ['status' => 'inactive']);
    $formerStaff = Staff::factory()->former()->create();
    $former = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'staff_id' => $formerStaff->id,
        'sales_code' => 'STATEMENT-FORMER', 'status' => 'active', 'effective_from' => now()->subDay(),
        'staff_category_snapshot' => 'sales', 'reporting_currency' => 'LKR', 'commission_eligible' => true,
    ]);
    $otherProfile = $makeProfile('STATEMENT-OTHER-LOCATION');
    $mismatchedStaff = Staff::factory()->create();
    $mismatched = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'staff_id' => $mismatchedStaff->id,
        'sales_code' => 'STATEMENT-MISMATCHED-STAFF', 'status' => 'active', 'effective_from' => now()->subDay(),
        'staff_category_snapshot' => 'sales', 'reporting_currency' => 'LKR', 'commission_eligible' => true,
    ]);
    $url = '/api/sales/commission-statement-profile-options';

    actingAs($admin, 'api')->getJson($url.'?search=STATEMENT-TARGET&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $target->id)
        ->assertJsonPath('data.data.0.label', 'STATEMENT-TARGET — Commission Seller');
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$target->id.'&search=no-match')
        ->assertOk()->assertJsonPath('data.data.0.value', $target->id);
    actingAs($admin, 'api')->getJson('/api/sales/commission-statement-profile-options?selected_id='.$target->id)
        ->assertOk()->assertJsonPath('data.data.0.value', $target->id);
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$otherProfile->id)
        ->assertOk()->assertJsonPath('data.data.0.value', $otherProfile->id);
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$mismatched->id)
        ->assertOk()->assertJsonPath('data.data.0.value', $mismatched->id);
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$ineligible->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$inactive->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$former->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'?per_page=51')->assertUnprocessable();
});
