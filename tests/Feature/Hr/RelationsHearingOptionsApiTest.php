<?php

use App\Models\Company;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns bounded scheduled hearings only for a case the actor may transition', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.relations.case-admin', 'hr.relations.case.transition');
    $caseId = relations_hearing_options_case($company->id, $admin->id);
    $first = relations_hearing_options_seed($caseId, $admin->id, now()->addDays(1));
    $second = relations_hearing_options_seed($caseId, $admin->id, now()->addDays(2));
    $completed = relations_hearing_options_seed($caseId, $admin->id, now()->addDays(3), 'completed');
    $otherCase = relations_hearing_options_case($company->id, $admin->id);
    $otherCaseHearing = relations_hearing_options_seed($otherCase, $admin->id, now()->addDays(4));

    $foreignCompany = Company::create(['name' => 'Other relations company']);
    $foreignCase = relations_hearing_options_case($foreignCompany->id, $admin->id);
    $foreignHearing = relations_hearing_options_seed($foreignCase, $admin->id, now()->addDays(5));

    $url = '/api/hr/relations/cases/'.$caseId.'/hearing-options?search=Asia%2FColombo';
    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=1')->assertOk()
        ->assertJsonPath('data.total', 2)->assertJsonPath('data.data.0.value', $first)
        ->assertJsonPath('data.data.0.metadata.timezone', 'Asia/Colombo')->assertDontSee('Sensitive hearing agenda');
    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=2')->assertOk()
        ->assertJsonPath('data.data.0.value', $second);
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$second)->assertOk()
        ->assertJsonPath('data.0.value', $second);
    foreach ([$completed, $otherCaseHearing, $foreignHearing] as $unavailable) {
        actingAs($admin, 'api')->getJson($url.'&selected_id='.$unavailable)->assertOk()->assertJsonCount(0, 'data');
    }
    actingAs($admin, 'api')->getJson('/api/hr/relations/cases/'.$foreignCase.'/hearing-options')->assertNotFound();
    actingAs($admin, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
    $unauthorized = Staff::factory()->create(['company_id' => $company->id])->user;
    actingAs($unauthorized, 'api')->getJson($url)->assertForbidden();
    $this->assertDatabaseHas('activity_log', [
        'description' => 'restricted_case_hearing_options_viewed', 'causer_id' => $admin->id,
    ]);
});

function relations_hearing_options_case(string $companyId, string $userId): string
{
    $id = (string) Str::uuid();
    DB::table('hr_relation_cases')->insert([
        'id' => $id, 'company_id' => $companyId, 'case_number' => 'REL-'.Str::upper(Str::random(12)),
        'case_type' => 'grievance', 'severity' => 'moderate', 'confidentiality' => 'restricted',
        'subject' => 'Protected test case', 'encrypted_summary' => encrypt('Protected summary'),
        'status' => 'hearing', 'opened_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function relations_hearing_options_seed(string $caseId, string $userId, $scheduledAt, string $status = 'scheduled'): string
{
    $id = (string) Str::uuid();
    DB::table('hr_relation_hearings')->insert([
        'id' => $id, 'case_id' => $caseId, 'scheduled_at' => $scheduledAt, 'timezone' => 'Asia/Colombo',
        'encrypted_agenda' => encrypt('Sensitive hearing agenda'), 'status' => $status,
        'scheduled_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}
