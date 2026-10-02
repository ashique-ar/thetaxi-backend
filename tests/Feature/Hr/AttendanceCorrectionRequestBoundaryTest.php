<?php

use App\Models\Staff;
use App\Models\User;
use App\Services\Hr\Attendance\AttendanceResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('validates correction values and replays a request by idempotency key', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    config(['hr.features.attendance_results' => true, 'hr.features.employee_self_service' => false]);
    $now = now();
    $firstResultId = (string) Str::uuid();
    $resultData = ['day_status' => 'present', 'worked_minutes' => 480, 'payable_minutes' => 480];
    DB::table('hr_attendance_daily_results')->insert([
        'id' => $firstResultId, 'company_id' => $company->id, 'staff_id' => $staff->id, 'work_date' => '2026-06-01', 'result_version' => 1,
        'day_status' => $resultData['day_status'], 'worked_minutes' => $resultData['worked_minutes'], 'payable_minutes' => $resultData['payable_minutes'],
        'late_minutes' => 0, 'early_leave_minutes' => 0, 'source_kind' => 'calculated', 'calculated_at' => $now,
        'input_checksum' => str_repeat('a', 64), 'result_checksum' => hash('sha256', json_encode($resultData, JSON_THROW_ON_ERROR)),
        'rule_snapshot' => json_encode([], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now,
    ]);
    $payload = [
        'staff_id' => $staff->id,
        'work_date' => '2026-06-01',
        'correction_type' => 'worked_minutes',
        'requested_values' => ['worked_minutes' => 420],
        'reason' => 'Corrected from approved evidence',
        'idempotency_key' => 'attendance-correction-1',
    ];

    $first = actingAs($admin, 'api')->postJson('/api/hr/attendance/corrections', $payload)->assertCreated();
    $firstId = $first->json('data.id');
    actingAs($admin, 'api')->postJson('/api/hr/attendance/corrections', $payload)
        ->assertOk()->assertJsonPath('data.id', $firstId);
    expect(DB::table('hr_attendance_correction_requests')->where('idempotency_key', $payload['idempotency_key'])->count())->toBe(1);
    expect(DB::table('activity_log')->where('description', 'attendance_correction_requested')->count())->toBe(1);

    actingAs($admin, 'api')->postJson('/api/hr/attendance/corrections', array_replace($payload, [
        'requested_values' => ['worked_minutes' => -1],
        'idempotency_key' => 'attendance-correction-negative',
    ]))->assertUnprocessable();
    actingAs($admin, 'api')->postJson('/api/hr/attendance/corrections', array_replace($payload, [
        'requested_values' => ['unknown_result_field' => 1],
        'idempotency_key' => 'attendance-correction-unknown',
    ]))->assertUnprocessable();
    actingAs($admin, 'api')->postJson('/api/hr/attendance/corrections', array_replace($payload, [
        'requested_values' => ['worked_minutes' => 421],
    ]))->assertStatus(409);

    $nextResultId = (string) Str::uuid();
    DB::table('hr_attendance_daily_results')->insert([
        'id' => $nextResultId, 'company_id' => $company->id, 'staff_id' => $staff->id, 'work_date' => '2026-06-01', 'result_version' => 2,
        'supersedes_id' => $firstResultId, 'day_status' => 'present', 'worked_minutes' => 480, 'payable_minutes' => 480,
        'late_minutes' => 0, 'early_leave_minutes' => 0, 'source_kind' => 'calculated', 'calculated_at' => $now,
        'input_checksum' => str_repeat('b', 64), 'result_checksum' => hash('sha256', json_encode($resultData, JSON_THROW_ON_ERROR)),
        'rule_snapshot' => json_encode([], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now,
    ]);
    $reviewer = User::factory()->create();
    expect(fn () => app(AttendanceResultService::class)->approveCorrection($firstId, $reviewer->id, 'Review correction.'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->assertDatabaseHas('hr_attendance_correction_requests', ['id' => $firstId, 'status' => 'pending_approval']);

    app(AttendanceResultService::class)->rejectCorrection($firstId, $reviewer->id, 'Evidence does not support this change.');
    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'hr-attendance',
        'description' => 'attendance_correction_rejected',
    ]);

    $currentPayload = array_replace($payload, [
        'correction_type' => 'payable_minutes',
        'requested_values' => ['payable_minutes' => 450],
        'idempotency_key' => 'attendance-correction-current-result',
    ]);
    $currentRequest = actingAs($admin, 'api')->postJson('/api/hr/attendance/corrections', $currentPayload)->assertCreated();
    $approvedResult = app(AttendanceResultService::class)->approveCorrection($currentRequest->json('data.id'), $reviewer->id, 'Verified attendance record.');
    expect($approvedResult->payable_minutes)->toBe(450);
    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'hr-attendance',
        'description' => 'attendance_correction_approved',
    ]);
});
