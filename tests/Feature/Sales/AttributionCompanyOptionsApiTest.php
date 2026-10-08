<?php

use App\Models\Company;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns the active designated default company for attribution preselection', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Default Attribution Company']);

    actingAs($admin, 'api')->getJson('/api/sales/attribution-administration-context')
        ->assertOk()->assertJsonPath('data.default_company_id', $company->id);
    actingAs($admin, 'api')->getJson('/api/sales/attribution-company-options')
        ->assertOk()->assertJsonPath('default_company_id', $company->id);

    DB::table('companies')->where('id', $company->id)->update(['is_active' => false]);
    actingAs($admin, 'api')->getJson('/api/sales/attribution-administration-context')
        ->assertOk()->assertJsonPath('data.default_company_id', null);
    actingAs($admin, 'api')->getJson('/api/sales/attribution-company-options')
        ->assertOk()->assertJsonPath('default_company_id', null);
});

it('searches and hydrates only companies in the actors effective attribution scope', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Scoped Attribution Company', 'city' => 'Colombo', 'is_default' => false]);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    UserContext::create(['user_id' => $staff->user_id, 'context_type' => 'staff', 'context_id' => $staff->id,
        'is_active' => true, 'created_user_id' => $admin->id]);
    $staff->user->givePermissionTo('sales.attributions.view');
    SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id,
        'sales_code' => 'ATTR-001', 'status' => 'active', 'effective_from' => now()->subDay(),
        'staff_category_snapshot' => 'sales', 'reporting_currency' => 'LKR', 'acquisition_eligible' => true,
    ]);
    $profile = SalesProfile::query()->where('staff_id', $staff->id)->firstOrFail();
    $staff->user->givePermissionTo('sales.attributions.correct');
    $foreign = Company::create(['name' => 'Foreign Attribution Company']);
    $deleted = Company::create(['name' => 'Deleted Attribution Company']); $deleted->delete();
    $url = '/api/sales/attribution-company-options';

    actingAs($staff->user, 'api')->getJson('/api/sales/attribution-administration-context')->assertOk()
        ->assertJsonPath('data.default_company_id', null);

    $response = actingAs($staff->user, 'api')->getJson($url.'?search=Scoped&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $company->id)->assertJsonPath('data.data.0.label', 'Scoped Attribution Company')
        ->assertJsonPath('data.data.0.metadata.city', 'Colombo')
        ->assertJsonPath('data.data.0.metadata.is_default', false)
        ->assertJsonPath('default_company_id', null);
    expect(array_keys($response->json('data.data.0')))->toBe(['value', 'label', 'metadata', 'status']);
    actingAs($staff->user, 'api')->getJson($url.'?selected_id='.$company->id.'&search=no-match')->assertOk()
        ->assertJsonPath('data.data.0.value', $company->id);
    actingAs($staff->user, 'api')->getJson($url.'?selected_id='.$foreign->id)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($staff->user, 'api')->getJson($url.'?selected_id='.$deleted->id)->assertOk()->assertJsonCount(0, 'data.data');
    $profileUrl = '/api/sales/attribution-profile-options?purpose=acquisition&company_id='.$company->id;
    actingAs($staff->user, 'api')->getJson($profileUrl.'&selected_id='.$profile->id)
        ->assertOk()->assertJsonPath('data.data.0.value', $profile->id);
    actingAs($staff->user, 'api')->getJson('/api/sales/attribution-profile-options?purpose=acquisition&cross_company=1&selected_id='.$profile->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($staff->user, 'api')->getJson($url.'?per_page=51')->assertUnprocessable();
    DB::table('staff')->where('id', $staff->id)->update(['employment_ended_at' => now()]);
    actingAs($staff->user, 'api')->getJson($url.'?selected_id='.$company->id)->assertForbidden();
    actingAs($staff->user, 'api')->getJson($profileUrl.'&selected_id='.$profile->id)->assertForbidden();
});

it('searches and hydrates bounded eligible attribution targets in the requested tenant scope', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Attribution Target Company']);
    $makeProfile = function (string $companyId, string $code, array $overrides = []) {
        $staff = Staff::factory()->create(['company_id' => $companyId, 'code' => $code.'-STAFF']);
        $staff->user->update(['first_name' => 'Target', 'last_name' => 'Seller']);
        return SalesProfile::query()->create(array_merge([
            'id' => (string) Str::uuid(), 'company_id' => $companyId, 'staff_id' => $staff->id,
            'sales_code' => $code, 'status' => 'active', 'effective_from' => now()->subDay(),
            'staff_category_snapshot' => 'sales', 'reporting_currency' => 'LKR',
            'acquisition_eligible' => true, 'collection_eligible' => true,
        ], $overrides));
    };
    $target = $makeProfile($company->id, 'ATTR-TARGET');
    $acquisitionOnly = $makeProfile($company->id, 'ATTR-ACQUISITION', ['collection_eligible' => false]);
    $otherCompany = Company::create(['name' => 'Other Attribution Target Company']);
    $foreign = $makeProfile($otherCompany->id, 'ATTR-FOREIGN');
    actingAs($admin, 'api')->getJson('/api/sales/attribution-administration-context')->assertOk()->assertJsonMissingPath('data.profiles');
    $url = '/api/sales/attribution-profile-options?purpose=collection&company_id='.$company->id;

    actingAs($admin, 'api')->getJson($url.'&search=ATTR-TARGET&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $target->id)
        ->assertJsonPath('data.data.0.label', 'ATTR-TARGET — Target Seller')
        ->assertJsonPath('data.data.0.metadata.company', 'Attribution Target Company');
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$target->id.'&search=no-match')
        ->assertOk()->assertJsonPath('data.data.0.value', $target->id);
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$foreign->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$acquisitionOnly->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson('/api/sales/attribution-profile-options?purpose=acquisition&cross_company=1&selected_id='.$foreign->id)
        ->assertOk()->assertJsonPath('data.data.0.value', $foreign->id);
    actingAs($admin, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
    actingAs($admin, 'api')->getJson('/api/sales/attribution-profile-options?purpose=collection')->assertUnprocessable();
});
