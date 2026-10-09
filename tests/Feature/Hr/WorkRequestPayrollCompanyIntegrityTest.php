<?php

use App\Models\Company;
use App\Models\Hr\Leave\LeaveBalanceEntry;
use App\Models\Staff;
use App\Models\User;
use App\Services\Hr\Workforce\WorkforceWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('blocks overtime payroll and time-off writes when their company links disagree', function () {
    [$actor, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $actor->id)->firstOrFail();
    $requester = User::factory()->create();
    $otherCompany = Company::create(['name' => 'Other Work Request Company', 'is_default' => false]);
    $today = now()->toDateString();
    $typeId = (string) Str::uuid();
    $validTimeOffTypeId = (string) Str::uuid();
    $policyId = (string) Str::uuid();

    DB::table('hr_leave_types')->insert([
        'id' => $typeId, 'company_id' => $company->id, 'code' => 'LIEU', 'name' => 'Time off in lieu',
        'category' => 'lieu', 'unit' => 'day', 'paid' => true, 'medical_confidential' => false,
        'effective_from' => $today, 'status' => 'active', 'created_by' => $actor->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_leave_types')->insert([
        'id' => $validTimeOffTypeId, 'company_id' => $company->id, 'code' => 'VALID-LIEU', 'name' => 'Valid time off in lieu',
        'category' => 'lieu', 'unit' => 'day', 'paid' => true, 'medical_confidential' => false,
        'effective_from' => $today, 'status' => 'active', 'created_by' => $actor->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_work_request_policies')->insert([
        'id' => $policyId, 'company_id' => $company->id, 'request_kind' => 'overtime',
        'code' => 'OVERTIME-1', 'version' => 1, 'rules' => '{}', 'effective_from' => $today,
        'status' => 'approved', 'created_by' => $actor->id, 'approved_by' => $actor->id,
        'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $payrollRequestId = (string) Str::uuid();
    $timeOffRequestId = (string) Str::uuid();
    $validPayrollRequestId = (string) Str::uuid();
    $validTimeOffRequestId = (string) Str::uuid();
    $insertRequest = function (string $id, string $requestCompany, string $settlement, array $rules, string $key) use ($staff, $policyId, $requester): void {
        DB::table('hr_work_requests')->insert([
            'id' => $id, 'company_id' => $requestCompany, 'staff_id' => $staff->id, 'policy_id' => $policyId,
            'request_kind' => 'overtime', 'starts_at' => now()->subHours(2), 'ends_at' => now()->subHour(),
            'requested_minutes' => 60, 'settlement_kind' => $settlement, 'status' => 'pending_approval',
            'reason' => 'Overtime request', 'request_snapshot' => json_encode(['rules' => $rules], JSON_THROW_ON_ERROR),
            'request_checksum' => str_repeat('b', 64), 'idempotency_key' => $key,
            'requested_by' => $requester->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $insertRequest($payrollRequestId, $otherCompany->id, 'pay', [], 'cross-company-work-payroll');
    $insertRequest($timeOffRequestId, $company->id, 'time_off', ['time_off_leave_type_id' => $typeId], 'cross-company-time-off-account');
    $insertRequest($validPayrollRequestId, $company->id, 'pay', [], 'valid-work-payroll');
    $insertRequest($validTimeOffRequestId, $company->id, 'time_off', ['time_off_leave_type_id' => $validTimeOffTypeId], 'valid-work-time-off');
    DB::table('hr_leave_balance_accounts')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $otherCompany->id, 'staff_id' => $staff->id,
        'leave_type_id' => $typeId, 'unit' => 'day', 'opened_at' => $today,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_leave_balance_accounts')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id,
        'leave_type_id' => $validTimeOffTypeId, 'unit' => 'day', 'opened_at' => $today,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    config(['hr.features.leave_overtime' => true]);
    $service = app(WorkforceWorkflowService::class);

    expect(fn () => $service->decideWorkRequest($payrollRequestId, 'approve', 'Approve', $actor->id))
        ->toThrow(HttpException::class);
    expect(fn () => $service->decideWorkRequest($timeOffRequestId, 'approve', 'Approve', $actor->id))
        ->toThrow(HttpException::class);
    $approved = $service->decideWorkRequest($validPayrollRequestId, 'approve', 'Approve', $actor->id);
    expect($approved->status)->toBe('approved');
    expect($service->decideWorkRequest($validPayrollRequestId, 'approve', 'Approve', $actor->id)->status)->toBe('approved');
    expect(fn () => $service->decideWorkRequest($validPayrollRequestId, 'approve', 'Changed note', $actor->id))
        ->toThrow(HttpException::class, 'Work-request retry does not match the original action, note and actor.');
    $otherApprover = User::factory()->create();
    expect(fn () => $service->decideWorkRequest($validPayrollRequestId, 'approve', 'Approve', $otherApprover->id))
        ->toThrow(HttpException::class, 'Work-request retry does not match the original action, note and actor.');
    $fact = DB::table('hr_payroll_input_facts')->where('source_id', $validPayrollRequestId)->first();
    expect(DB::table('hr_work_requests')->whereIn('id', [$payrollRequestId, $timeOffRequestId])->where('status', 'pending_approval')->count())->toBe(2)
        ->and(DB::table('hr_payroll_input_facts')->whereIn('source_id', [$payrollRequestId, $timeOffRequestId])->count())->toBe(0)
        ->and(DB::table('hr_payroll_input_facts')->where('source_id', $validPayrollRequestId)->count())->toBe(1)
        ->and($fact->company_id)->toBe($company->id)
        ->and($fact->staff_id)->toBe($staff->id)
        ->and(DB::table('hr_work_request_events')->where('work_request_id', $validPayrollRequestId)->where('event_type', 'approve')->count())->toBe(1)
        ->and(DB::table('hr_leave_balance_entries')->where('source_type', 'work_request')->whereIn('source_id', [$payrollRequestId, $timeOffRequestId])->count())->toBe(0)
        ->and(DB::table('hr_work_request_events')->whereIn('work_request_id', [$payrollRequestId, $timeOffRequestId])->count())->toBe(0);

    $approvedTimeOff = $service->decideWorkRequest($validTimeOffRequestId, 'approve', 'Approve time off', $actor->id);
    $timeOffCredit = DB::table('hr_leave_balance_entries as entry')
        ->join('hr_leave_balance_accounts as account', 'account.id', '=', 'entry.account_id')
        ->where('entry.source_type', 'work_request')->where('entry.source_id', $validTimeOffRequestId)
        ->where('entry.entry_type', 'time_off_credit')
        ->first(['entry.id', 'entry.minutes', 'entry.effective_date', 'account.company_id', 'account.staff_id', 'account.leave_type_id']);
    expect($approvedTimeOff->status)->toBe('approved')
        ->and($service->decideWorkRequest($validTimeOffRequestId, 'approve', 'Approve time off', $actor->id)->status)->toBe('approved')
        ->and(DB::table('hr_leave_balance_entries')->where('source_type', 'work_request')->where('source_id', $validTimeOffRequestId)
            ->where('entry_type', 'time_off_credit')->count())->toBe(1)
        ->and((int) $timeOffCredit->minutes)->toBe(60)
        ->and(CarbonImmutable::parse($timeOffCredit->effective_date)->toDateString())->toBe($today)
        ->and($timeOffCredit->company_id)->toBe($company->id)
        ->and($timeOffCredit->staff_id)->toBe($staff->id)
        ->and($timeOffCredit->leave_type_id)->toBe($validTimeOffTypeId)
        ->and(DB::table('activity_log')->where('subject_type', LeaveBalanceEntry::class)->where('subject_id', $timeOffCredit->id)->count())->toBe(1);
    expect(fn () => $service->decideWorkRequest($validTimeOffRequestId, 'approve', 'Changed time-off note', $actor->id))
        ->toThrow(HttpException::class, 'Work-request retry does not match the original action, note and actor.');

    DB::table('hr_payroll_input_facts')->where('source_id', $validPayrollRequestId)->delete();
    DB::table('hr_leave_balance_entries')->where('source_type', 'work_request')->where('source_id', $validTimeOffRequestId)->delete();
    expect(fn () => $service->decideWorkRequest($validTimeOffRequestId, 'approve', 'Approve time off', $actor->id))
        ->toThrow(HttpException::class, 'The approved time-off credit no longer matches its work request.');

    $replayException = null;
    try {
        $service->decideWorkRequest($validPayrollRequestId, 'approve', 'Approve', $actor->id);
    } catch (HttpException $exception) {
        $replayException = $exception;
    }
    expect($replayException)->toBeInstanceOf(HttpException::class)
        ->and($replayException->getStatusCode())->toBe(409)
        ->and($replayException->getMessage())->toBe('The approved overtime payroll fact no longer matches its work request.');
});

it('requires an active locked company for work-request submission, approval and replay', function () {
    [$approver, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $approver->id)->firstOrFail();
    $requester = User::factory()->create();
    $policyId = (string) Str::uuid();
    $now = now();
    DB::table('hr_work_request_policies')->insert([
        'id' => $policyId, 'company_id' => $company->id, 'request_kind' => 'overtime', 'code' => 'ACTIVE-COMPANY',
        'version' => 1, 'rules' => '{}', 'effective_from' => $now->toDateString(), 'status' => 'approved',
        'created_by' => $approver->id, 'approved_by' => $approver->id, 'approved_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    config(['hr.features.leave_overtime' => true]);
    $data = [
        'company_id' => $company->id, 'staff_id' => $staff->id, 'policy_id' => $policyId,
        'request_kind' => 'overtime', 'starts_at' => $now->addHours(2)->toIso8601String(),
        'ends_at' => $now->addHours(3)->toIso8601String(), 'settlement_kind' => 'informational',
        'reason' => 'Reviewed overtime request.', 'idempotency_key' => 'inactive-company-work-request',
    ];
    $service = app(WorkforceWorkflowService::class);
    $request = $service->submitWorkRequest($data, $requester->id);
    DB::table('companies')->where('id', $company->id)->update(['is_active' => false]);

    expect(fn () => $service->decideWorkRequest($request->id, 'approve', 'Approve', $approver->id))
        ->toThrow(HttpException::class, 'Workforce operations require an active legal entity.');
    expect(fn () => $service->submitWorkRequest([...$data, 'idempotency_key' => 'inactive-company-new-work-request'], $requester->id))
        ->toThrow(HttpException::class, 'Workforce operations require an active legal entity.');
    expect(fn () => $service->submitWorkRequest($data, $requester->id))
        ->toThrow(HttpException::class, 'Workforce operations require an active legal entity.');
    expect(DB::table('hr_work_requests')->where('id', $request->id)->value('status'))->toBe('pending_approval')
        ->and(DB::table('hr_work_request_events')->where('work_request_id', $request->id)->count())->toBe(1)
        ->and(DB::table('hr_payroll_input_facts')->where('source_id', $request->id)->exists())->toBeFalse();
});
