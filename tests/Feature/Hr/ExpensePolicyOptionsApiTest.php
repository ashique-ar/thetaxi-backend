<?php

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns only searchable current approved expense policies for the actor legal entity', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $first = hr_expense_policy_option_seed($company, $admin->id, 'TRAVEL-A');
    $second = hr_expense_policy_option_seed($company, $admin->id, 'TRAVEL-B');
    $pending = hr_expense_policy_option_seed($company, $admin->id, 'TRAVEL-P', 'pending_approval');
    $expired = hr_expense_policy_option_seed($company, $admin->id, 'TRAVEL-OLD', 'approved', today()->subDays(5)->toDateString(), today()->subDay()->toDateString());
    $future = hr_expense_policy_option_seed($company, $admin->id, 'TRAVEL-FUTURE', 'approved', today()->addDay()->toDateString());
    $foreignCompany = Company::create(['name' => 'Other expense policy tenant']);
    $foreign = hr_expense_policy_option_seed($foreignCompany, $admin->id, 'TRAVEL-FOREIGN');
    $url = '/api/hr/service-operations/expense-policy-options?search=travel';

    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=1')->assertOk()
        ->assertJsonPath('data.total', 2)->assertJsonPath('data.data.0.value', $first)
        ->assertJsonPath('data.data.0.label', 'TRAVEL-A v1');
    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=2')->assertOk()
        ->assertJsonPath('data.data.0.value', $second);
    actingAs($admin, 'api')->getJson('/api/hr/service-operations/expense-policy-options?selected_id='.$second)
        ->assertOk()->assertJsonPath('data.0.value', $second);
    foreach ([$pending, $expired, $future, $foreign] as $excluded) {
        actingAs($admin, 'api')->getJson('/api/hr/service-operations/expense-policy-options?selected_id='.$excluded)
            ->assertOk()->assertJsonCount(0, 'data');
    }
    actingAs($admin, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
});

function hr_expense_policy_option_seed(Company $company, string $actorId, string $code, string $status = 'approved', ?string $effectiveFrom = null, ?string $effectiveUntil = null): string
{
    $id = (string) Str::uuid();
    DB::table('hr_expense_policy_versions')->insert([
        'id' => $id, 'company_id' => $company->id, 'code' => $code, 'version' => 1,
        'status' => $status, 'rules' => json_encode([], JSON_THROW_ON_ERROR),
        'effective_from' => $effectiveFrom ?? today()->subDay()->toDateString(), 'effective_until' => $effectiveUntil,
        'created_by' => $actorId, 'approved_by' => $status === 'approved' ? $actorId : null,
        'approved_at' => $status === 'approved' ? now() : null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}
