<?php

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns searchable exact-hydrated approved lifecycle templates for the actor company', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $first = lifecycle_template_option_seed($company->id, $admin->id, 'LIFE-A', 'approved');
    $second = lifecycle_template_option_seed($company->id, $admin->id, 'LIFE-B', 'approved');
    $pending = lifecycle_template_option_seed($company->id, $admin->id, 'LIFE-C', 'pending_approval');
    $foreignCompany = Company::create(['name' => 'Other lifecycle tenant']);
    $foreign = lifecycle_template_option_seed($foreignCompany->id, $admin->id, 'LIFE-D', 'approved');
    $url = '/api/hr/lifecycle/template-options?company_id='.$company->id.'&search=onboarding';

    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=1')->assertOk()
        ->assertJsonPath('data.total', 2)->assertJsonPath('data.data.0.value', $first)
        ->assertJsonPath('data.data.0.label', 'LIFE-A v1 (onboarding)')
        ->assertJsonPath('data.data.0.metadata.case_type', 'onboarding');
    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=2')->assertOk()
        ->assertJsonPath('data.data.0.value', $second);
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$first)->assertOk()->assertJsonPath('data.0.value', $first);
    foreach ([$pending, $foreign] as $excluded) {
        actingAs($admin, 'api')->getJson($url.'&selected_id='.$excluded)->assertOk()->assertJsonCount(0, 'data');
    }
    actingAs($admin, 'api')->getJson('/api/hr/lifecycle/template-options?company_id='.$foreignCompany->id)->assertForbidden();
    actingAs($admin, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
});

function lifecycle_template_option_seed(string $companyId, string $actorId, string $code, string $status): string
{
    $id = (string) Str::uuid();
    DB::table('hr_lifecycle_templates')->insert([
        'id' => $id, 'company_id' => $companyId, 'case_type' => 'onboarding', 'code' => $code, 'version' => 1,
        'applicability' => json_encode([], JSON_THROW_ON_ERROR), 'task_definitions' => json_encode([['code' => 'task', 'title' => 'Task']], JSON_THROW_ON_ERROR),
        'status' => $status, 'created_by' => $actorId, 'approved_by' => $status === 'approved' ? $actorId : null,
        'approved_at' => $status === 'approved' ? now() : null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}
