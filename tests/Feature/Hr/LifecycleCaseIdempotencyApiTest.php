<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('replays a lifecycle case submission for the same company and key without duplicating tasks', function () {
    config(['hr.features.employee_self_service' => true]);
    Permission::findOrCreate('hr.lifecycle.manage', 'api');
    Permission::findOrCreate('hr.lifecycle.view', 'api');
    Permission::findOrCreate('staff.view-all', 'api');

    $user = User::factory()->create();
    $user->givePermissionTo(['hr.lifecycle.manage', 'hr.lifecycle.view', 'staff.view-all']);
    $company = Company::create(['name' => 'Lifecycle Idempotency Company', 'is_active' => true, 'is_default' => true]);
    $actor = Staff::factory()->create(['user_id' => $user->id, 'company_id' => $company->id]);
    $context = UserContext::create(['user_id' => $user->id, 'context_type' => 'staff', 'context_id' => $actor->id,
        'is_active' => true, 'created_user_id' => $user->id]);
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];
    $subject = Staff::factory()->create(['company_id' => $company->id]);
    $templateId = (string) Str::uuid();
    DB::table('hr_lifecycle_templates')->insert([
        'id' => $templateId, 'company_id' => $company->id, 'case_type' => 'onboarding',
        'code' => 'IDEMPOTENT-ONBOARDING', 'version' => 1, 'applicability' => '{}',
        'task_definitions' => json_encode([['code' => 'account', 'title' => 'Set up account']], JSON_THROW_ON_ERROR),
        'status' => 'approved', 'created_by' => $user->id, 'approved_by' => User::factory()->create()->id,
        'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $payload = [
        'company_id' => $company->id, 'staff_id' => $subject->id, 'template_id' => $templateId,
        'effective_date' => today()->toDateString(), 'idempotency_key' => (string) Str::uuid(),
    ];
    $url = '/api/hr/lifecycle/cases';
    $first = actingAs($user, 'api')->withHeaders($headers)->postJson($url, $payload)->assertCreated();
    $replay = actingAs($user, 'api')->withHeaders($headers)->postJson($url, $payload)->assertOk();
    actingAs($user, 'api')->withHeaders($headers)->postJson($url, array_replace($payload, ['effective_date' => today()->addDay()->toDateString()]))
        ->assertConflict();

    $unitId = (string) Str::uuid();
    DB::table('hr_organization_units')->insert([
        'id' => $unitId, 'company_id' => $company->id, 'unit_type' => 'department', 'code' => 'PREHIRE',
        'name' => 'Pre-hire', 'effective_from' => today()->toDateString(), 'created_user_id' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $designationId = (string) Str::uuid();
    DB::table('hr_designations')->insert([
        'id' => $designationId, 'company_id' => $company->id, 'code' => 'PREHIRE-ROLE', 'name' => 'Pre-hire role',
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $positionId = (string) Str::uuid();
    DB::table('hr_positions')->insert([
        'id' => $positionId, 'company_id' => $company->id, 'organization_unit_id' => $unitId,
        'designation_id' => $designationId, 'position_number' => 'PREHIRE-001', 'title' => 'Pre-hire role',
        'effective_from' => today()->toDateString(), 'status' => 'vacant', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $requisitionId = (string) Str::uuid();
    DB::table('hr_job_requisitions')->insert([
        'id' => $requisitionId, 'company_id' => $company->id, 'position_id' => $positionId,
        'code' => 'REQ-LIFECYCLE-PREHIRE', 'title' => 'Pre-hire role', 'headcount' => 1,
        'requirements' => '[]', 'status' => 'approved', 'requested_by' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $candidateId = (string) Str::uuid();
    DB::table('hr_candidates')->insert([
        'id' => $candidateId, 'company_id' => $company->id, 'candidate_code' => 'CAN-LIFECYCLE-PREHIRE',
        'encrypted_profile' => encrypt('{}'), 'identity_fingerprint' => hash('sha256', 'prehire@example.test'),
        'source_type' => 'direct', 'consent_at' => now(), 'status' => 'active', 'created_by' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $applicationId = (string) Str::uuid();
    DB::table('hr_candidate_applications')->insert([
        'id' => $applicationId, 'company_id' => $company->id, 'candidate_id' => $candidateId,
        'requisition_id' => $requisitionId, 'stage' => 'offer', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $preHireCaseId = (string) Str::uuid();
    DB::table('hr_lifecycle_cases')->insert([
        'id' => $preHireCaseId, 'company_id' => $company->id, 'application_id' => $applicationId,
        'template_id' => $templateId, 'case_type' => 'preboarding', 'status' => 'open',
        'effective_date' => today()->toDateString(), 'case_snapshot' => '{}', 'opened_by' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($replay->json('data.id'))->toBe($first->json('data.id'))
        ->and(DB::table('hr_lifecycle_cases')->where('company_id', $company->id)->whereNotNull('idempotency_key')->count())->toBe(1)
        ->and(DB::table('hr_lifecycle_tasks')->where('case_id', $first->json('data.id'))->count())->toBe(1)
        ->and(DB::table('activity_log')->where('log_name', 'hr-lifecycle')->where('description', 'lifecycle_case_opened')->count())->toBe(1);

    actingAs($user, 'api')->withHeaders($headers)->getJson('/api/hr/lifecycle/cases')->assertOk()
        ->assertJsonFragment(['id' => $preHireCaseId])
        ->assertJsonMissingPath('data.data.0.idempotency_key')
        ->assertJsonMissingPath('data.data.0.request_payload_checksum');
});
