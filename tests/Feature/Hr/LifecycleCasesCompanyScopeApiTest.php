<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('keeps lifecycle case reads in the active actor company even with global Staff visibility', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();

    $actor = User::factory()->create();
    $role = Role::create(['name' => 'lifecycle_cases_company_scope_tester', 'guard_name' => 'api']);
    $role->givePermissionTo(['hr.lifecycle.view', 'staff.view-all']);
    $actor->assignRole($role);

    $company = Company::create(['name' => 'Lifecycle Read Company', 'is_active' => true, 'is_default' => true]);
    $otherCompany = Company::create(['name' => 'Other Lifecycle Read Company', 'is_active' => true, 'is_default' => false]);
    Staff::factory()->create(['user_id' => $actor->id, 'company_id' => $company->id]);
    $subjects = [
        $company->id => Staff::factory()->create(['company_id' => $company->id]),
        $otherCompany->id => Staff::factory()->create(['company_id' => $otherCompany->id]),
    ];
    $caseIds = [];

    foreach ($subjects as $companyId => $subject) {
        $templateId = (string) Str::uuid();
        $caseId = (string) Str::uuid();
        DB::table('hr_lifecycle_templates')->insert([
            'id' => $templateId, 'company_id' => $companyId, 'case_type' => 'onboarding',
            'code' => 'CASE-' . substr($companyId, 0, 8), 'version' => 1, 'applicability' => '{}',
            'task_definitions' => '[]', 'status' => 'approved', 'created_by' => $actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('hr_lifecycle_cases')->insert([
            'id' => $caseId, 'company_id' => $companyId, 'staff_id' => $subject->id,
            'template_id' => $templateId, 'case_type' => 'onboarding', 'status' => 'open',
            'effective_date' => today()->toDateString(), 'case_snapshot' => '{}', 'opened_by' => $actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $caseIds[$companyId] = $caseId;
    }

    $response = actingAs($actor, 'api')->getJson('/api/hr/lifecycle/cases')->assertOk();
    $visibleIds = collect($response->json('data.data'))->pluck('id')->all();

    expect($visibleIds)->toContain($caseIds[$company->id]);
    expect($visibleIds)->not->toContain($caseIds[$otherCompany->id]);
});
