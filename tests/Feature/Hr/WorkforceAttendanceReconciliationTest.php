<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use App\Services\Hr\Workforce\WorkforceWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('reconciles Work Requests and timesheets against only the latest company-owned attendance result per date', function () {
    config(['hr.features.leave_overtime' => true]);
    $requester = User::factory()->create();
    $decider = User::factory()->create();
    $company = Company::create(['name' => 'Reconciliation Company']);
    $otherCompany = Company::create(['name' => 'Other Reconciliation Company', 'is_default' => false]);
    $staff = Staff::factory()->create(['user_id' => $requester->id, 'company_id' => $company->id]);
    $policyId = (string) Str::uuid();
    DB::table('hr_work_request_policies')->insert([
        'id' => $policyId, 'company_id' => $company->id, 'request_kind' => 'overtime', 'code' => 'OT-RECON',
        'version' => 1, 'rules' => json_encode(['maximum_request_minutes' => 10080], JSON_THROW_ON_ERROR),
        'effective_from' => '2026-01-01', 'status' => 'approved', 'created_by' => $requester->id,
        'approved_by' => $decider->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $insertResult = function (string $companyId, string $workDate, int $version, int $minutes, ?string $supersedesId = null) use ($staff): string {
        $id = (string) Str::uuid();
        DB::table('hr_attendance_daily_results')->insert([
            'id' => $id, 'company_id' => $companyId, 'staff_id' => $staff->id, 'work_date' => $workDate,
            'result_version' => $version, 'supersedes_id' => $supersedesId, 'day_status' => 'present',
            'worked_minutes' => $minutes, 'late_minutes' => 0, 'early_leave_minutes' => 0, 'payable_minutes' => $minutes,
            'source_kind' => 'calculated', 'calculated_at' => now(), 'input_checksum' => str_repeat('a', 64),
            'result_checksum' => str_repeat('b', 64), 'rule_snapshot' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };
    $firstVersionId = $insertResult($company->id, '2026-11-02', 1, 120);
    $insertResult($company->id, '2026-11-02', 2, 240, $firstVersionId);
    $insertResult($otherCompany->id, '2026-11-03', 1, 900);

    $service = app(WorkforceWorkflowService::class);
    $request = $service->submitWorkRequest([
        'company_id' => $company->id, 'staff_id' => $staff->id, 'policy_id' => $policyId,
        'request_kind' => 'overtime', 'starts_at' => '2026-11-02T09:00:00+05:30', 'ends_at' => '2026-11-03T10:00:00+05:30',
        'settlement_kind' => 'informational', 'reason' => 'Reconcile the approved overtime request.', 'idempotency_key' => 'work-request-attendance-reconciliation',
    ], $requester->id);
    $decided = $service->decideWorkRequest($request->id, 'approve', 'Reviewed latest attendance versions.', $decider->id);
    expect(json_decode($decided->request_snapshot, true, 512, JSON_THROW_ON_ERROR)['attendance_reconciliation']['worked_minutes'])->toBe(240);

    $sheet = $service->saveTimesheet([
        'company_id' => $company->id, 'staff_id' => $staff->id, 'period_start' => '2026-11-02', 'period_end' => '2026-11-03',
        'entries' => [
            ['work_date' => '2026-11-02', 'minutes' => 35, 'entry_mode' => 'manual', 'activity_code' => 'operations', 'billable' => false],
            ['work_date' => '2026-11-03', 'minutes' => 25, 'entry_mode' => 'manual', 'activity_code' => 'operations', 'billable' => false],
        ],
    ], $requester->id);
    $submitted = $service->transitionTimesheet($sheet->id, 'submit', 'Submit for reconciliation.', $requester->id);
    $reconciliation = json_decode($submitted->reconciliation_snapshot, true, 512, JSON_THROW_ON_ERROR);
    expect($reconciliation)->toMatchArray([
        'timesheet_minutes' => 60,
        'attendance_worked_minutes' => 240,
        'variance_minutes' => -180,
        'not_assumed_equal' => true,
    ]);

    $mismatchedSheetId = (string) Str::uuid();
    DB::table('hr_timesheets')->insert([
        'id' => $mismatchedSheetId, 'company_id' => $otherCompany->id, 'staff_id' => $staff->id,
        'period_start' => '2026-11-10', 'period_end' => '2026-11-11', 'status' => 'draft', 'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $requester->givePermissionTo('hr.timesheets.view');
    $context = UserContext::create([
        'user_id' => $requester->id, 'context_type' => 'staff', 'context_id' => $staff->id,
        'is_active' => true, 'created_user_id' => $requester->id,
    ]);
    actingAs($requester, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson('/api/hr/workforce/timesheets')->assertOk()
        ->assertJsonFragment(['id' => $sheet->id])->assertJsonMissing(['id' => $mismatchedSheetId]);

    expect(fn () => $service->saveTimesheet([
        'company_id' => $company->id, 'staff_id' => $staff->id, 'period_start' => '2026-11-10', 'period_end' => '2026-11-11',
        'entries' => [['work_date' => '2026-11-10', 'minutes' => 30, 'entry_mode' => 'manual', 'activity_code' => 'operations', 'billable' => false]],
    ], $requester->id))->toThrow(HttpException::class, 'The existing timesheet belongs to another legal entity.');
    expect(fn () => $service->transitionTimesheet($mismatchedSheetId, 'submit', 'Submit mismatched sheet.', $requester->id))
        ->toThrow(HttpException::class, 'Staff and timesheet legal entities must match.');
    expect(DB::table('hr_timesheet_events')->where('timesheet_id', $mismatchedSheetId)->count())->toBe(0);
});

it('rechecks the active company before timesheet creation and transition', function () {
    config(['hr.features.leave_overtime' => true]);
    $user = User::factory()->create();
    $company = Company::create(['name' => 'Inactive Timesheet Company', 'is_active' => true, 'is_default' => true]);
    $staff = Staff::factory()->create(['user_id' => $user->id, 'company_id' => $company->id]);
    $service = app(WorkforceWorkflowService::class);
    $sheet = $service->saveTimesheet([
        'company_id' => $company->id, 'staff_id' => $staff->id, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
        'entries' => [['work_date' => '2026-01-01', 'minutes' => 60, 'entry_mode' => 'manual', 'billable' => false]],
    ], $user->id);
    DB::table('companies')->where('id', $company->id)->update(['is_active' => false]);

    expect(fn () => $service->saveTimesheet([
        'company_id' => $company->id, 'staff_id' => $staff->id, 'period_start' => '2026-02-01', 'period_end' => '2026-02-28',
        'entries' => [['work_date' => '2026-02-01', 'minutes' => 60, 'entry_mode' => 'manual', 'billable' => false]],
    ], $user->id))->toThrow(HttpException::class, 'Workforce operations require an active legal entity.');
    expect(fn () => $service->transitionTimesheet($sheet->id, 'submit', 'Submit timesheet.', $user->id))
        ->toThrow(HttpException::class, 'Workforce operations require an active legal entity.');
    expect(DB::table('hr_timesheets')->where('id', $sheet->id)->value('status'))->toBe('draft')
        ->and(DB::table('hr_timesheet_entries')->where('timesheet_id', $sheet->id)->count())->toBe(1)
        ->and(DB::table('hr_timesheet_events')->where('timesheet_id', $sheet->id)->count())->toBe(0);
});
