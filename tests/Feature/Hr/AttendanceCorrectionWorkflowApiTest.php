<?php

use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use App\Services\Hr\Attendance\AttendanceResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('submits and approves a policy-mapped attendance correction as an immutable result version', function () {
    [$requester, $company] = hr_seed_admin_actor();
    $requesterStaff = Staff::query()->where('user_id', $requester->id)->firstOrFail();
    $requesterContext = UserContext::query()->where('user_id', $requester->id)->where('context_id', $requesterStaff->id)->firstOrFail();
    $requesterHeaders = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $requesterContext->id];
    $reviewer = User::factory()->create();
    $reviewer->assignRole('admin');
    $reviewerStaff = Staff::factory()->create(['user_id' => $reviewer->id, 'company_id' => $company->id, 'staff_type' => 'admin']);
    $reviewerContext = UserContext::create(['user_id' => $reviewer->id, 'context_type' => 'staff', 'context_id' => $reviewerStaff->id, 'is_active' => true, 'created_user_id' => $reviewer->id]);
    $reviewerHeaders = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $reviewerContext->id];
    $policyId = (string) Str::uuid();
    $rosterId = (string) Str::uuid();
    $resultId = (string) Str::uuid();
    actingAs($requester, 'api')->withHeaders($requesterHeaders)->postJson('/api/hr/attendance/policies', [
        'company_id' => $company->id, 'code' => 'CORRECTION', 'name' => 'Correction policy',
        'rules' => ['correction_mappings' => [['type' => 'worked_minutes', 'field' => 'worked_minutes']]], 'effective_from' => '2026-01-01',
    ])->assertCreated()->assertJsonPath('data.status', 'pending_approval');
    $policyId = (string) DB::table('hr_attendance_policies')->where('company_id', $company->id)->where('code', 'CORRECTION')->value('id');
    actingAs($reviewer, 'api')->withHeaders($reviewerHeaders)->postJson('/api/hr/attendance/policies/'.$policyId.'/approve', [])->assertOk()->assertJsonPath('data.status', 'approved');
    $calendarId = (string) Str::uuid();
    $shiftId = (string) Str::uuid();
    DB::table('hr_work_calendars')->insert([
        'id' => $calendarId, 'company_id' => $company->id, 'code' => 'CAL', 'name' => 'Calendar', 'timezone' => 'Asia/Colombo',
        'weekly_working_days' => json_encode(['monday']), 'effective_from' => '2026-01-01', 'status' => 'active',
        'created_by' => $requester->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_shift_definitions')->insert([
        'id' => $shiftId, 'company_id' => $company->id, 'code' => 'SHIFT', 'name' => 'Shift', 'start_time' => '09:00', 'end_time' => '18:00',
        'ends_next_day' => false, 'unpaid_break_minutes' => 60, 'grace_in_minutes' => 10, 'grace_out_minutes' => 10,
        'minimum_half_day_minutes' => 240, 'minimum_full_day_minutes' => 480, 'timezone' => 'Asia/Colombo', 'effective_from' => '2026-01-01',
        'status' => 'active', 'created_by' => $requester->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_roster_assignments')->insert([
        'id' => $rosterId, 'company_id' => $company->id, 'staff_id' => $requesterStaff->id,
        'calendar_id' => $calendarId, 'shift_id' => $shiftId, 'policy_id' => $policyId,
        'effective_from' => '2026-01-01', 'reason' => 'Test roster', 'created_by' => $requester->id,
        'approved_by' => $reviewer->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $baseResult = app(AttendanceResultService::class)->calculate((string) $company->id, (string) $requesterStaff->id, '2026-06-01', (string) $requester->id);
    $resultId = (string) $baseResult->id;
    DB::table('hr_attendance_daily_results')->where('id', $resultId)->update([
        'day_status' => 'present', 'worked_minutes' => 360, 'payable_minutes' => 360,
        'result_checksum' => hash('sha256', json_encode(['day_status' => 'present', 'worked_minutes' => 360, 'payable_minutes' => 360], JSON_THROW_ON_ERROR)),
    ]);
    $payload = [
        'result_id' => $resultId, 'correction_type' => 'worked_minutes',
        'requested_values' => ['worked_minutes' => 420], 'reason' => 'Approved time record shows 420 minutes.',
        'evidence_references' => ['timesheet:2026-06-01'], 'idempotency_key' => 'correction-workflow-1',
    ];
    $options = actingAs($requester, 'api')->withHeaders($requesterHeaders)->getJson('/api/hr/attendance/correction-options?company_id='.$company->id.'&result_id='.$resultId);
    expect($options->status())->toBe(200, json_encode($options->json(), JSON_THROW_ON_ERROR));
    $options->assertJsonPath('data.mappings.0.type', 'worked_minutes')->assertJsonPath('data.mappings.0.field', 'worked_minutes');
    $submitted = actingAs($requester, 'api')->withHeaders($requesterHeaders)->postJson('/api/hr/attendance/corrections', $payload)
        ->assertCreated()->assertJsonPath('data.status', 'pending_approval')->assertJsonPath('data.mapping_policy_id', $policyId);
    $correctionId = $submitted->json('data.id');
    actingAs($requester, 'api')->withHeaders($requesterHeaders)->postJson('/api/hr/attendance/corrections', $payload)
        ->assertOk()->assertJsonPath('idempotent_replay', true);
    actingAs($reviewer, 'api')->withHeaders($reviewerHeaders)->getJson('/api/hr/attendance/corrections?company_id='.$company->id)
        ->assertOk()->assertJsonPath('data.data.0.requested_values.worked_minutes', 420)->assertJsonPath('data.data.0.mapping_snapshot.field', 'worked_minutes');
    $approved = actingAs($reviewer, 'api')->withHeaders($reviewerHeaders)->postJson('/api/hr/attendance/corrections/'.$correctionId.'/approve', ['decision_note' => 'Verified against the signed timesheet.'])
        ->assertOk()->assertJsonPath('data.result_version', 2)->assertJsonPath('data.worked_minutes', 420)->assertJsonPath('data.source_kind', 'manual_correction');
    $newResultId = $approved->json('data.id');
    expect($newResultId)->not->toBe($resultId);
    $this->assertDatabaseHas('hr_attendance_daily_results', ['id' => $resultId, 'result_version' => 1, 'worked_minutes' => 360]);
    $this->assertDatabaseHas('hr_attendance_daily_results', ['id' => $newResultId, 'result_version' => 2, 'supersedes_id' => $resultId, 'worked_minutes' => 420]);
    $this->assertDatabaseHas('hr_attendance_correction_requests', ['id' => $correctionId, 'status' => 'approved', 'result_id' => $newResultId]);
    actingAs($reviewer, 'api')->withHeaders($reviewerHeaders)->postJson('/api/hr/attendance/corrections/'.$correctionId.'/approve', ['decision_note' => 'Verified against the signed timesheet.'])
        ->assertOk()->assertJsonPath('data.id', $newResultId);
    actingAs($requester, 'api')->withHeaders($requesterHeaders)->postJson('/api/hr/attendance/corrections', $payload)
        ->assertOk()->assertJsonPath('idempotent_replay', true);
    expect(DB::table('hr_attendance_daily_results')->where('company_id', $company->id)->where('staff_id', $requesterStaff->id)->whereDate('work_date', '2026-06-01')->count())->toBe(2);
    $secondRequest = actingAs($requester, 'api')->withHeaders($requesterHeaders)->postJson('/api/hr/attendance/corrections', [
        ...$payload, 'result_id' => $newResultId, 'requested_values' => ['worked_minutes' => 450], 'reason' => 'A second reviewed adjustment is required.', 'idempotency_key' => 'correction-workflow-2',
    ])->assertCreated();
    $secondResult = actingAs($reviewer, 'api')->withHeaders($reviewerHeaders)->postJson('/api/hr/attendance/corrections/'.$secondRequest->json('data.id').'/approve', ['decision_note' => 'Second timesheet check completed.'])
        ->assertOk()->assertJsonPath('data.result_version', 3)->assertJsonPath('data.worked_minutes', 450);
    expect(app(AttendanceResultService::class)->calculate((string) $company->id, (string) $requesterStaff->id, '2026-06-01', (string) $reviewer->id)->id)->toBe($secondResult->json('data.id'));
    expect(DB::table('hr_attendance_daily_results')->where('company_id', $company->id)->where('staff_id', $requesterStaff->id)->whereDate('work_date', '2026-06-01')->count())->toBe(3);
});

