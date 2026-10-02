<?php

use App\Models\Company;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('requires a selected legal entity when the actor has Staff identities in multiple companies', function () {
    [$user] = hr_seed_admin_actor([], true);
    config(['hr.features.attendance_results' => true]);
    $otherCompany = Company::create(['name' => 'Second Attendance Company']);
    $unassignedCompany = Company::create(['name' => 'Unassigned Attendance Company']);
    Staff::factory()->create(['user_id' => $user->id, 'company_id' => $otherCompany->id]);

    $options = actingAs($user, 'api')->getJson('/api/hr/attendance/company-options')->assertOk();
    $companyIds = collect($options->json('data'))->pluck('value')->all();
    expect($companyIds)->toContain($otherCompany->id);
    expect($companyIds)->not->toContain($unassignedCompany->id);

    actingAs($user, 'api')->getJson('/api/hr/attendance/periods')
        ->assertForbidden()
        ->assertJsonPath('message', 'Select an authorized Staff legal entity for attendance access.');

    actingAs($user, 'api')->getJson('/api/hr/attendance/periods?company_id='.$otherCompany->id)
        ->assertOk();

    actingAs($user, 'api')->postJson('/api/hr/attendance/periods', [
        'company_id' => $otherCompany->id,
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'timezone' => 'Asia/Colombo',
    ])->assertCreated()->assertJsonPath('data.company_id', $otherCompany->id);
    actingAs($user, 'api')->getJson('/api/hr/attendance/periods?company_id='.$otherCompany->id)
        ->assertOk()->assertJsonCount(1, 'data');
    actingAs($user, 'api')->getJson('/api/hr/attendance/periods?company_id='.$unassignedCompany->id)
        ->assertForbidden();

    actingAs($user, 'api')->postJson('/api/hr/attendance/periods', [
        'company_id' => $unassignedCompany->id,
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'timezone' => 'Asia/Colombo',
    ])->assertForbidden();
});

it('keeps results, corrections, and exceptions inside the selected authorized company', function () {
    [$user, $company] = hr_seed_admin_actor();
    $actorStaff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $otherCompany = Company::create(['name' => 'Second Attendance Company']);
    Staff::factory()->create(['user_id' => $user->id, 'company_id' => $otherCompany->id]);
    $otherStaff = Staff::factory()->create(['company_id' => $otherCompany->id]);
    $result = ['day_status' => 'present', 'worked_minutes' => 480, 'payable_minutes' => 480];
    $resultIds = [];

    foreach ([[$company->id, $actorStaff->id], [$otherCompany->id, $otherStaff->id]] as [$companyId, $staffId]) {
        $resultId = (string) Str::uuid();
        $resultIds[$companyId] = $resultId;
        DB::table('hr_attendance_daily_results')->insert([
            'id' => $resultId, 'company_id' => $companyId, 'staff_id' => $staffId, 'work_date' => '2026-06-01',
            'result_version' => 1, 'day_status' => 'present', 'worked_minutes' => 480, 'payable_minutes' => 480,
            'late_minutes' => 0, 'early_leave_minutes' => 0, 'source_kind' => 'calculated', 'calculated_at' => now(),
            'input_checksum' => str_repeat('a', 64), 'result_checksum' => hash('sha256', json_encode($result, JSON_THROW_ON_ERROR)),
            'rule_snapshot' => json_encode([], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('hr_attendance_correction_requests')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId, 'staff_id' => $staffId, 'work_date' => '2026-06-01',
            'current_result_id' => $resultId, 'correction_type' => 'worked_minutes', 'requested_values' => json_encode(['worked_minutes' => 420]),
            'reason' => 'Reviewed evidence', 'status' => 'pending_approval', 'idempotency_key' => 'scope-correction-'.$companyId,
            'requested_by' => $user->id, 'request_checksum' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('hr_attendance_exceptions')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId, 'staff_id' => $staffId, 'daily_result_id' => $resultId,
            'exception_type' => 'incomplete', 'severity' => 'medium', 'status' => 'open', 'evidence' => json_encode(['day_status' => 'incomplete']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    actingAs($user, 'api')->getJson('/api/hr/attendance/results?company_id='.$otherCompany->id)
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.staff_id', $otherStaff->id);

    actingAs($user, 'api')->getJson('/api/hr/attendance/corrections?company_id='.$otherCompany->id)
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.staff_id', $otherStaff->id);

    actingAs($user, 'api')->getJson('/api/hr/attendance/exceptions?company_id='.$otherCompany->id)
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.staff_id', $otherStaff->id);

    actingAs($user, 'api')->getJson('/api/hr/attendance/reports/summary?company_id='.$otherCompany->id.'&from=2026-06-01&to=2026-06-01&group_by=staff')
        ->assertOk()
        ->assertJsonPath('data.summary.staff_count', 1);
});

it('hides attendance correction, exception, and period actions outside the actor company', function () {
    [$user] = hr_seed_admin_actor([], true);
    config(['hr.features.attendance_results' => true]);
    $foreignCompany = Company::create(['name' => 'Foreign Attendance Company']);
    $foreignStaff = Staff::factory()->create(['company_id' => $foreignCompany->id]);
    $resultId = (string) Str::uuid();
    $correctionId = (string) Str::uuid();
    $exceptionId = (string) Str::uuid();
    $periodId = (string) Str::uuid();
    DB::table('hr_attendance_daily_results')->insert([
        'id' => $resultId, 'company_id' => $foreignCompany->id, 'staff_id' => $foreignStaff->id,
        'work_date' => '2026-06-01', 'result_version' => 1, 'day_status' => 'present', 'worked_minutes' => 480,
        'payable_minutes' => 480, 'late_minutes' => 0, 'early_leave_minutes' => 0, 'source_kind' => 'calculated',
        'calculated_at' => now(), 'input_checksum' => str_repeat('a', 64), 'result_checksum' => str_repeat('b', 64),
        'rule_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_attendance_correction_requests')->insert([
        'id' => $correctionId, 'company_id' => $foreignCompany->id, 'staff_id' => $foreignStaff->id,
        'work_date' => '2026-06-01', 'current_result_id' => $resultId, 'correction_type' => 'worked_minutes',
        'requested_values' => '{"worked_minutes":420}', 'reason' => 'Evidence', 'status' => 'pending_approval',
        'idempotency_key' => 'foreign-correction-action', 'requested_by' => $user->id, 'request_checksum' => str_repeat('c', 64),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_attendance_exceptions')->insert([
        'id' => $exceptionId, 'company_id' => $foreignCompany->id, 'staff_id' => $foreignStaff->id,
        'daily_result_id' => $resultId, 'exception_type' => 'incomplete', 'severity' => 'medium', 'status' => 'open',
        'evidence' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_attendance_periods')->insert([
        'id' => $periodId, 'company_id' => $foreignCompany->id, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
        'timezone' => 'Asia/Colombo', 'status' => 'open', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach (['approve', 'reject'] as $action) {
        actingAs($user, 'api')->postJson('/api/hr/attendance/corrections/'.$correctionId.'/'.$action, ['decision_note' => 'Reviewed'])
            ->assertNotFound();
        actingAs($user, 'api')->postJson('/api/hr/attendance/corrections/'.Str::uuid().'/'.$action, ['decision_note' => 'Reviewed'])
            ->assertNotFound();
    }
    actingAs($user, 'api')->postJson('/api/hr/attendance/exceptions/'.$exceptionId.'/resolve', ['resolution_note' => 'Reviewed'])
        ->assertNotFound();
    actingAs($user, 'api')->postJson('/api/hr/attendance/exceptions/'.Str::uuid().'/resolve', ['resolution_note' => 'Reviewed'])
        ->assertNotFound();
    actingAs($user, 'api')->postJson('/api/hr/attendance/periods/'.$periodId.'/transition', [
        'action' => 'review', 'reason' => 'Reviewed', 'expected_version' => 1,
    ])->assertNotFound();
    actingAs($user, 'api')->postJson('/api/hr/attendance/periods/'.Str::uuid().'/transition', [
        'action' => 'review', 'reason' => 'Reviewed', 'expected_version' => 1,
    ])->assertNotFound();
});
