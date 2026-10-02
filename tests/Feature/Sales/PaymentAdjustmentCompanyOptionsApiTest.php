<?php

use App\Models\Company;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and hydrates only companies in the actors effective Sales scope', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Scoped FX Company', 'city' => 'Colombo']);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    UserContext::create(['user_id' => $staff->user_id, 'context_type' => 'staff', 'context_id' => $staff->id,
        'is_active' => true, 'created_user_id' => $admin->id]);
    $staff->user->givePermissionTo('sales.payment-adjustments.create');
    SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id,
        'sales_code' => 'FX-001', 'status' => 'active', 'effective_from' => now()->subDay(),
        'staff_category_snapshot' => 'sales', 'reporting_currency' => 'LKR', 'collection_eligible' => true,
    ]);
    $foreign = Company::create(['name' => 'Foreign FX Company']);
    $deleted = Company::create(['name' => 'Deleted FX Company']);
    $deleted->delete();
    $url = '/api/sales/payment-adjustment-company-options';

    $response = actingAs($staff->user, 'api')->getJson($url.'?search=Scoped&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $company->id)->assertJsonPath('data.data.0.label', 'Scoped FX Company')
        ->assertJsonPath('data.data.0.metadata.city', 'Colombo');
    expect(array_keys($response->json('data.data.0')))->toBe(['value', 'label', 'metadata', 'status']);
    actingAs($staff->user, 'api')->getJson($url.'?selected_id='.$company->id.'&search=no-match')->assertOk()
        ->assertJsonPath('data.data.0.value', $company->id);
    actingAs($staff->user, 'api')->getJson($url.'?selected_id='.$foreign->id)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($staff->user, 'api')->getJson($url.'?selected_id='.$deleted->id)->assertOk()->assertJsonCount(0, 'data.data');
    $staff->update(['employment_ended_at' => now()]);
    actingAs($staff->user, 'api')->getJson($url.'?selected_id='.$company->id)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($staff->user, 'api')->getJson($url.'?per_page=51')->assertUnprocessable();
});
