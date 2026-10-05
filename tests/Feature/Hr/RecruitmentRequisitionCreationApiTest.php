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

it('searches active positions in the selected Staff company and idempotently submits requisitions', function () {
    config(['hr.features.employee_self_service' => true]);
    Permission::findOrCreate('hr.recruitment.manage', 'api');

    $user = User::factory()->create();
    $user->givePermissionTo('hr.recruitment.manage');
    $company = Company::create(['name' => 'Recruitment Company', 'is_active' => true, 'is_default' => true]);
    $foreignCompany = Company::create(['name' => 'Foreign Recruitment Company', 'is_active' => true, 'is_default' => false]);
    $actor = Staff::factory()->create(['user_id' => $user->id, 'company_id' => $company->id]);
    $context = UserContext::create([
        'user_id' => $user->id, 'context_type' => 'staff', 'context_id' => $actor->id,
        'is_active' => true, 'created_user_id' => $user->id,
    ]);
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];

    $unitId = (string) Str::uuid();
    DB::table('hr_organization_units')->insert([
        'id' => $unitId, 'company_id' => $company->id, 'unit_type' => 'department', 'code' => 'OPS',
        'name' => 'Operations', 'effective_from' => today()->toDateString(), 'created_user_id' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $designationId = (string) Str::uuid();
    DB::table('hr_designations')->insert([
        'id' => $designationId, 'company_id' => $company->id, 'code' => 'OPS-ROLE', 'name' => 'Operations role',
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $positionId = (string) Str::uuid();
    DB::table('hr_positions')->insert([
        'id' => $positionId, 'company_id' => $company->id, 'organization_unit_id' => $unitId,
        'designation_id' => $designationId, 'position_number' => 'OPS-100', 'title' => 'Operations officer',
        'effective_from' => today()->toDateString(), 'status' => 'vacant', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $foreignUnitId = (string) Str::uuid();
    DB::table('hr_organization_units')->insert([
        'id' => $foreignUnitId, 'company_id' => $foreignCompany->id, 'unit_type' => 'department', 'code' => 'FOREIGN-OPS',
        'name' => 'Foreign operations', 'effective_from' => today()->toDateString(), 'created_user_id' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $foreignDesignationId = (string) Str::uuid();
    DB::table('hr_designations')->insert([
        'id' => $foreignDesignationId, 'company_id' => $foreignCompany->id, 'code' => 'FOREIGN-ROLE', 'name' => 'Foreign role',
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $foreignPositionId = (string) Str::uuid();
    DB::table('hr_positions')->insert([
        'id' => $foreignPositionId, 'company_id' => $foreignCompany->id, 'organization_unit_id' => $foreignUnitId,
        'designation_id' => $foreignDesignationId, 'position_number' => 'OPS-FOREIGN', 'title' => 'Foreign position',
        'effective_from' => today()->toDateString(), 'status' => 'vacant', 'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($user, 'api')->withHeaders($headers)
        ->getJson('/api/hr/recruitment/position-options?search=OPS-100')->assertOk()
        ->assertJsonPath('data.0.value', $positionId)
        ->assertJsonPath('data.0.label', 'OPS-100 · Operations officer');
    actingAs($user, 'api')->withHeaders($headers)
        ->getJson('/api/hr/recruitment/position-options?search=OPS-FOREIGN')->assertOk()->assertJsonCount(0, 'data');

    $payload = [
        'position_id' => $positionId, 'code' => 'REQ-OPS-100', 'title' => 'Operations officer',
        'headcount' => 2, 'requirements' => ['Shift coverage', 'Valid driving licence'],
        'idempotency_key' => (string) Str::uuid(),
    ];
    $url = '/api/hr/recruitment/requisitions';
    $created = actingAs($user, 'api')->withHeaders($headers)->postJson($url, $payload)->assertCreated()
        ->assertJsonPath('data.status', 'pending_approval')
        ->assertJsonMissingPath('data.requirements')->assertJsonMissingPath('data.company_id');
    $replay = actingAs($user, 'api')->withHeaders($headers)->postJson($url, $payload)->assertOk()
        ->assertJsonPath('data.id', $created->json('data.id'))
        ->assertJsonPath('data.status', 'pending_approval');
    actingAs($user, 'api')->withHeaders($headers)->postJson($url, array_replace($payload, ['title' => 'Different title']))
        ->assertConflict();
    expect(DB::table('activity_log')->where('description', 'job_requisition_submitted')->count())->toBe(1)
        ->and(DB::table('hr_job_requisitions')->where('company_id', $company->id)->count())->toBe(1)
        ->and($replay->json('data.id'))->toBe($created->json('data.id'));
});