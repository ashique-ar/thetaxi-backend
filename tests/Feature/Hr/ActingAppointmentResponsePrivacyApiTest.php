<?php

use App\Models\Staff;
use App\Services\Hr\ActingAppointmentAdministrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('does not echo private acting appointment fields from the service result', function () {
    [$admin, $company] = hr_seed_admin_actor();
    config(['hr.features.people_core' => true]);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    $id = (string) Str::uuid();
    $appointments = Mockery::mock(ActingAppointmentAdministrationService::class);
    $appointments->shouldReceive('create')->once()->andReturn([
        'id' => $id,
        'company_id' => $company->id,
        'staff_id' => $staff->id,
        'acting_position_id' => (string) Str::uuid(),
        'acting_manager_staff_id' => (string) Str::uuid(),
        'acting_assignment_id' => (string) Str::uuid(),
        'restoration_assignment_id' => null,
        'effective_from' => '2026-10-01',
        'effective_until' => '2026-11-01',
        'status' => 'pending_approval',
        'version' => 1,
        'reason' => 'Private request reason',
        'requested_by' => $admin->id,
        'request_checksum' => str_repeat('a', 64),
        'idempotency_key' => 'private-acting-key',
        'acting_assignment_snapshot' => ['manager_staff_id' => (string) Str::uuid()],
        'restoration_assignment_snapshot' => ['manager_staff_id' => (string) Str::uuid()],
    ]);
    app()->instance(ActingAppointmentAdministrationService::class, $appointments);

    $response = actingAs($admin, 'api')->postJson('/api/hr/organization/acting-appointments', [
        'staff_id' => $staff->id,
        'acting_position_id' => (string) Str::uuid(),
        'effective_from' => '2026-10-01',
        'effective_until' => '2026-11-01',
        'reason' => 'Private request reason',
        'idempotency_key' => 'private-acting-key',
    ])->assertCreated()->json('data');

    expect(array_keys($response))->toEqualCanonicalizing([
        'id', 'effective_from', 'effective_until', 'status', 'version', 'has_restoration',
    ])->and($response)->toMatchArray([
        'id' => $id,
        'status' => 'pending_approval',
        'version' => 1,
        'has_restoration' => false,
    ]);
});

it('returns readable acting appointment register rows without linked-record identifiers', function () {
    [$admin, $company] = hr_seed_admin_actor();
    config(['hr.features.people_core' => true]);
    $employeeUser = \App\Models\User::factory()->create(['first_name' => 'Alex', 'last_name' => 'Employee']);
    $staff = Staff::factory()->create(['user_id' => $employeeUser->id, 'company_id' => $company->id, 'code' => 'EMP-204']);
    $spellId = (string) Str::uuid();
    $positionId = (string) Str::uuid();
    $assignmentId = (string) Str::uuid();
    $appointmentId = (string) Str::uuid();
    $now = now();

    DB::table('hr_employment_spells')->insert([
        'id' => $spellId, 'staff_id' => $staff->id, 'company_id' => $company->id, 'spell_number' => 1,
        'joined_at' => '2026-01-01', 'service_date' => '2026-01-01', 'gratuity_service_start' => '2026-01-01',
        'status' => 'active', 'created_user_id' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $unitId = (string) Str::uuid();
    $designationId = (string) Str::uuid();
    DB::table('hr_organization_units')->insert([
        'id' => $unitId, 'company_id' => $company->id, 'unit_type' => 'department', 'code' => 'TEST-UNIT',
        'name' => 'Test Unit', 'timezone' => 'Asia/Colombo', 'status' => 'active', 'effective_from' => '2026-01-01',
        'created_user_id' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_designations')->insert([
        'id' => $designationId, 'company_id' => $company->id, 'code' => 'TEST-DESIGNATION', 'name' => 'Test Designation',
        'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_positions')->insert([
        'id' => $positionId, 'company_id' => $company->id, 'organization_unit_id' => $unitId, 'designation_id' => $designationId,
        'position_number' => 'TEST-POS-204', 'title' => 'Acting Lead', 'headcount_limit' => 1, 'status' => 'active',
        'effective_from' => '2026-01-01', 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_employment_assignments')->insert([
        'id' => $assignmentId, 'employment_spell_id' => $spellId, 'staff_id' => $staff->id, 'company_id' => $company->id,
        'position_id' => $positionId, 'assignment_type' => 'primary', 'effective_from' => '2026-01-01',
        'change_reason' => 'test_setup', 'snapshot' => json_encode(['position_id' => $positionId], JSON_THROW_ON_ERROR),
        'created_user_id' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_acting_appointments')->insert([
        'id' => $appointmentId, 'company_id' => $company->id, 'staff_id' => $staff->id, 'employment_spell_id' => $spellId,
        'source_assignment_id' => $assignmentId, 'acting_position_id' => $positionId, 'effective_from' => '2026-10-01',
        'effective_until' => '2026-11-01', 'status' => 'approved', 'version' => 2, 'reason' => 'Private reason',
        'source_assignment_snapshot' => json_encode([], JSON_THROW_ON_ERROR), 'acting_assignment_snapshot' => json_encode([], JSON_THROW_ON_ERROR),
        'restoration_assignment_snapshot' => json_encode([], JSON_THROW_ON_ERROR), 'excluded_impact_snapshot' => json_encode([], JSON_THROW_ON_ERROR),
        'request_checksum' => str_repeat('b', 64), 'idempotency_key' => 'register-privacy-row', 'requested_by' => $admin->id,
        'acting_assignment_id' => $assignmentId, 'restoration_assignment_id' => $assignmentId,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    $response = actingAs($admin, 'api')->getJson('/api/hr/organization/acting-appointments')->assertOk();
    $row = $response->json('data.data.0');
    expect(array_keys($row))->toEqualCanonicalizing([
        'id', 'effective_from', 'effective_until', 'status', 'version', 'reason', 'employee_number',
        'employee_first_name', 'employee_last_name', 'position_number', 'position_title', 'has_restoration',
    ])->and($row)->toMatchArray([
        'id' => $appointmentId, 'employee_number' => 'EMP-204', 'employee_first_name' => 'Alex',
        'employee_last_name' => 'Employee', 'position_number' => 'TEST-POS-204', 'has_restoration' => true,
    ]);
});
