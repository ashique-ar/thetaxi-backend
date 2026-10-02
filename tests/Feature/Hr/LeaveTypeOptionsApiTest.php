<?php

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns searchable, paginated leave types scoped to the actor company and requested status', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $first = hr_type_option_seed($company, $admin->id, 'ANNUAL-A', 'Annual leave');
    $second = hr_type_option_seed($company, $admin->id, 'ANNUAL-B', 'Annual leave B');
    $inactive = hr_type_option_seed($company, $admin->id, 'ANNUAL-X', 'Annual leave archived', 'inactive');
    $foreignCompany = Company::create(['name' => 'Other leave type tenant']);
    $foreign = hr_type_option_seed($foreignCompany, $admin->id, 'ANNUAL-F', 'Annual leave foreign');
    $url = '/api/hr/workforce/leave-type-options?company_id='.$company->id.'&status=active&search=annual';

    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=1')->assertOk()
        ->assertJsonPath('data.total', 2)->assertJsonPath('data.data.0.value', $first)
        ->assertJsonPath('data.data.0.label', 'Annual leave - ANNUAL-A');
    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=2')->assertOk()
        ->assertJsonPath('data.data.0.value', $second);
    actingAs($admin, 'api')->getJson('/api/hr/workforce/leave-type-options?company_id='.$company->id.'&selected_id='.$second)
        ->assertOk()->assertJsonPath('data.0.value', $second);
    foreach ([$inactive, $foreign] as $excluded) {
        actingAs($admin, 'api')->getJson('/api/hr/workforce/leave-type-options?company_id='.$company->id.'&status=active&selected_id='.$excluded)
            ->assertOk()->assertJsonCount(0, 'data');
    }
    actingAs($admin, 'api')->getJson('/api/hr/workforce/leave-type-options?company_id='.$foreignCompany->id)->assertForbidden();
    actingAs($admin, 'api')->getJson('/api/hr/workforce/leave-type-options')->assertOk()->assertJsonCount(0, 'data');
    actingAs($admin, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
});

function hr_type_option_seed(Company $company, string $actorId, string $code, string $name, string $status = 'active'): string
{
    $id = (string) Str::uuid();
    DB::table('hr_leave_types')->insert([
        'id' => $id, 'company_id' => $company->id, 'code' => $code, 'name' => $name,
        'category' => 'annual', 'unit' => 'day', 'paid' => true, 'medical_confidential' => false,
        'effective_from' => today()->subDay()->toDateString(), 'status' => $status, 'created_by' => $actorId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}
