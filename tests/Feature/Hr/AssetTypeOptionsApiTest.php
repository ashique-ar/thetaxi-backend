<?php

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and exactly hydrates only active asset types in the actor company', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $first = asset_type_option_seed($company, 'LAPTOP', 'Laptop');
    $second = asset_type_option_seed($company, 'MONITOR', 'Monitor');
    $inactive = asset_type_option_seed($company, 'RETIRED', 'Retired type', 'inactive');
    $foreign = asset_type_option_seed(Company::create(['name' => 'Other asset tenant']), 'PRIVATE', 'Private type');
    $url = '/api/hr/assets/type-options?search=top';

    actingAs($admin, 'api')->getJson($url)->assertOk()->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.value', $first)->assertJsonPath('data.data.0.label', 'Laptop · LAPTOP');
    actingAs($admin, 'api')->getJson('/api/hr/assets/type-options?search=')->assertOk()
        ->assertJsonPath('data.total', 2)->assertJsonPath('data.data.0.value', $first);
    actingAs($admin, 'api')->getJson('/api/hr/assets/type-options?selected_id='.$second)->assertOk()
        ->assertJsonPath('data.0.value', $second);
    foreach ([$inactive, $foreign] as $excluded) {
        actingAs($admin, 'api')->getJson('/api/hr/assets/type-options?selected_id='.$excluded)
            ->assertOk()->assertJsonCount(0, 'data');
    }
    actingAs($admin, 'api')->getJson('/api/hr/assets/type-options?per_page=51')->assertUnprocessable();
});

it('rejects a type deactivated after lookup without creating an asset request or event', function () {
    [$admin, $company] = hr_seed_admin_actor();
    config(['hr.features.advanced_assets' => true]);
    $type = asset_type_option_seed($company, 'RETIRED', 'Retired type', 'inactive');

    actingAs($admin, 'api')->postJson('/api/hr/assets/requests', [
        'asset_type_id' => $type, 'request_kind' => 'new_issue', 'reason_code' => 'equipment',
        'reason' => 'Work equipment required', 'required_from' => today()->toDateString(),
    ])->assertUnprocessable();
    expect(DB::table('hr_asset_requests')->exists())->toBeFalse()
        ->and(DB::table('hr_asset_request_events')->exists())->toBeFalse();
});

function asset_type_option_seed(Company $company, string $code, string $name, string $status = 'active'): string
{
    $id = (string) Str::uuid();
    DB::table('hr_asset_types')->insert([
        'id' => $id, 'company_id' => $company->id, 'code' => $code, 'name' => $name,
        'returnable' => true, 'consumable' => false, 'requires_employee_ack' => true,
        'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}
