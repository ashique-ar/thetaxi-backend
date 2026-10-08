<?php

use App\Models\Company;
use App\Models\Staff;
use App\Services\Hr\Leave\LeaveWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('blocks an approval when the leave request company does not match its staff and policy', function () {
    [$user, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $otherCompany = Company::create(['name' => 'Other Leave Company', 'is_default' => false]);
    $typeId = (string) Str::uuid();
    $policyId = (string) Str::uuid();
    $requestId = (string) Str::uuid();
    $today = now()->toDateString();

    DB::table('hr_leave_types')->insert([
        'id' => $typeId, 'company_id' => $company->id, 'code' => 'UNPAID', 'name' => 'Unpaid leave',
        'category' => 'other', 'unit' => 'day', 'paid' => false, 'medical_confidential' => false,
        'effective_from' => $today, 'status' => 'active', 'created_by' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_leave_policies')->insert([
        'id' => $policyId, 'company_id' => $company->id, 'leave_type_id' => $typeId,
        'code' => 'UNPAID-1', 'version' => 1, 'rules' => '{}', 'effective_from' => $today,
        'status' => 'approved', 'created_by' => $user->id, 'approved_by' => $user->id,
        'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_leave_requests')->insert([
        'id' => $requestId, 'company_id' => $otherCompany->id, 'staff_id' => $staff->id,
        'leave_type_id' => $typeId, 'policy_id' => $policyId, 'start_date' => $today, 'end_date' => $today,
        'unit' => 'day', 'requested_minutes' => 480, 'reserved_minutes' => 480, 'status' => 'pending_approval',
        'reason' => 'Malformed cross-company request', 'calculation_snapshot' => '{}', 'coverage_snapshot' => 'null',
        'request_checksum' => str_repeat('a', 64), 'idempotency_key' => 'cross-company-leave-approval',
        'requested_by' => $user->id, 'approval_level' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    config(['hr.features.leave_overtime' => true]);

    expect(fn () => app(LeaveWorkflowService::class)->decide($requestId, 'approve', 'Approve', $user->id, $staff->id))
        ->toThrow(HttpException::class);
    expect(DB::table('hr_leave_requests')->where('id', $requestId)->value('status'))->toBe('pending_approval')
        ->and(DB::table('hr_leave_balance_entries')->where('leave_request_id', $requestId)->count())->toBe(0)
        ->and(DB::table('hr_payroll_input_facts')->where('source_id', $requestId)->count())->toBe(0);
});

it('blocks leave decisions after the company becomes inactive', function () {
    [$user, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $typeId = (string) Str::uuid();
    $policyId = (string) Str::uuid();
    $requestId = (string) Str::uuid();
    $now = now();

    DB::table('hr_leave_types')->insert([
        'id' => $typeId, 'company_id' => $company->id, 'code' => 'INACTIVE', 'name' => 'Annual leave',
        'category' => 'annual', 'unit' => 'day', 'paid' => true, 'effective_from' => $now->toDateString(),
        'status' => 'active', 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_policies')->insert([
        'id' => $policyId, 'company_id' => $company->id, 'leave_type_id' => $typeId, 'code' => 'INACTIVE-1',
        'version' => 1, 'rules' => '{}', 'effective_from' => $now->toDateString(), 'status' => 'approved',
        'created_by' => $user->id, 'approved_by' => $user->id, 'approved_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_requests')->insert([
        'id' => $requestId, 'company_id' => $company->id, 'staff_id' => $staff->id,
        'leave_type_id' => $typeId, 'policy_id' => $policyId, 'start_date' => $now->toDateString(),
        'end_date' => $now->toDateString(), 'unit' => 'day', 'requested_minutes' => 480, 'reserved_minutes' => 480,
        'status' => 'pending_approval', 'reason' => 'Leave request', 'calculation_snapshot' => '{}',
        'request_checksum' => str_repeat('c', 64), 'idempotency_key' => 'inactive-company-leave',
        'requested_by' => $user->id, 'approval_level' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('companies')->where('id', $company->id)->update(['is_active' => false]);
    config(['hr.features.leave_overtime' => true]);

    expect(fn () => app(LeaveWorkflowService::class)->decide($requestId, 'approve', 'Approve', $user->id, $staff->id))
        ->toThrow(HttpException::class);
    expect(DB::table('hr_leave_requests')->where('id', $requestId)->value('status'))->toBe('pending_approval')
        ->and(DB::table('hr_leave_balance_entries')->where('leave_request_id', $requestId)->exists())->toBeFalse();
});

it('does not use a foreign company assignment to authorize a leave extension', function () {
    [$user, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $foreignCompany = Company::create(['name' => 'Foreign Extension Assignment', 'is_default' => false]);
    $typeId = (string) Str::uuid();
    $policyId = (string) Str::uuid();
    $requestId = (string) Str::uuid();
    $now = now();

    DB::table('hr_leave_types')->insert([
        'id' => $typeId, 'company_id' => $company->id, 'code' => 'EXTENSION', 'name' => 'Annual leave',
        'category' => 'annual', 'unit' => 'day', 'paid' => true, 'effective_from' => '2026-01-01',
        'status' => 'active', 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_policies')->insert([
        'id' => $policyId, 'company_id' => $company->id, 'leave_type_id' => $typeId, 'code' => 'EXTENSION-1',
        'version' => 1, 'rules' => json_encode(['weekend_days' => [], 'maximum_consecutive_days' => 366]),
        'effective_from' => '2026-01-01', 'status' => 'approved', 'created_by' => $user->id,
        'approved_by' => $user->id, 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_policy_assignments')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $foreignCompany->id, 'staff_id' => $staff->id,
        'policy_id' => $policyId, 'effective_from' => '2026-01-01', 'reason' => 'Malformed foreign assignment',
        'created_by' => $user->id, 'approved_by' => $user->id, 'approved_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_requests')->insert([
        'id' => $requestId, 'company_id' => $company->id, 'staff_id' => $staff->id,
        'leave_type_id' => $typeId, 'policy_id' => $policyId, 'start_date' => '2026-10-01', 'end_date' => '2026-10-02',
        'unit' => 'day', 'requested_minutes' => 960, 'reserved_minutes' => 960, 'status' => 'approved',
        'reason' => 'Approved leave', 'calculation_snapshot' => '{}', 'request_checksum' => str_repeat('b', 64),
        'idempotency_key' => 'extension-foreign-assignment', 'requested_by' => $user->id,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    config(['hr.features.leave_overtime' => true]);

    expect(fn () => app(LeaveWorkflowService::class)->extend($requestId, '2026-10-03', 'Extend leave', $user->id))
        ->toThrow(HttpException::class, 'The leave policy is not assigned for the full extended interval.');
    expect(DB::table('hr_leave_request_days')->where('leave_request_id', $requestId)->count())->toBe(0)
        ->and(DB::table('hr_leave_requests')->where('id', $requestId)->value('end_date'))->toBe('2026-10-02');

    $service = file_get_contents(app_path('Services/Hr/Leave/LeaveWorkflowService.php'));
    $extension = substr($service, strpos($service, 'public function extend('));
    expect($extension)->toContain("->where('company_id', \$row->company_id)->where('staff_id', \$row->staff_id)->where('id', '!=', \$row->id)");
});

it('records each unpaid leave extension against its own company-bound event', function () {
    [$user, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $now = now();
    $typeId = (string) Str::uuid();
    $policyId = (string) Str::uuid();
    $requestId = (string) Str::uuid();
    $accountId = (string) Str::uuid();

    DB::table('hr_leave_types')->insert([
        'id' => $typeId, 'company_id' => $company->id, 'code' => 'UNPAID-EXT', 'name' => 'Unpaid leave',
        'category' => 'other', 'unit' => 'day', 'paid' => false, 'medical_confidential' => false,
        'effective_from' => '2026-01-01', 'status' => 'active', 'created_by' => $user->id,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_policies')->insert([
        'id' => $policyId, 'company_id' => $company->id, 'leave_type_id' => $typeId, 'code' => 'UNPAID-EXT-1',
        'version' => 1, 'rules' => json_encode(['minutes_per_day' => 480, 'weekend_days' => ['saturday', 'sunday'], 'maximum_consecutive_days' => 366]),
        'effective_from' => '2026-01-01', 'status' => 'approved', 'created_by' => $user->id,
        'approved_by' => $user->id, 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_policy_assignments')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id,
        'policy_id' => $policyId, 'effective_from' => '2026-01-01', 'reason' => 'Assigned for payroll extension coverage',
        'created_by' => $user->id, 'approved_by' => $user->id, 'approved_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_requests')->insert([
        'id' => $requestId, 'company_id' => $company->id, 'staff_id' => $staff->id, 'leave_type_id' => $typeId,
        'policy_id' => $policyId, 'start_date' => '2026-10-01', 'end_date' => '2026-10-02', 'unit' => 'day',
        'requested_minutes' => 960, 'reserved_minutes' => 960, 'status' => 'approved', 'reason' => 'Unpaid leave request',
        'calculation_snapshot' => '{}', 'coverage_snapshot' => 'null', 'request_checksum' => str_repeat('c', 64),
        'idempotency_key' => 'unpaid-leave-extension-facts', 'requested_by' => $user->id,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_balance_accounts')->insert([
        'id' => $accountId, 'company_id' => $company->id, 'staff_id' => $staff->id, 'leave_type_id' => $typeId,
        'unit' => 'day', 'opened_at' => '2026-01-01', 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_balance_entries')->insert([
        'id' => (string) Str::uuid(), 'account_id' => $accountId, 'leave_request_id' => null, 'entry_type' => 'opening',
        'minutes' => 10000, 'effective_date' => '2026-01-01', 'source_type' => 'extension_test_opening',
        'source_id' => (string) Str::uuid(), 'reason' => 'Fixture opening balance', 'rule_snapshot' => '{}',
        'entry_checksum' => str_repeat('d', 64), 'posted_by' => $user->id, 'posted_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    config(['hr.features.leave_overtime' => true]);

    $service = app(LeaveWorkflowService::class);
    $service->extend($requestId, '2026-10-05', 'First extension', $user->id);
    $service->extend($requestId, '2026-10-06', 'Second extension', $user->id);
    $replayed = $service->extend($requestId, '2026-10-05', 'First extension', $user->id);
    expect($replayed->end_date)->toBe('2026-10-06');
    expect(fn () => $service->extend($requestId, '2026-10-05', 'Changed retry reason', $user->id))
        ->toThrow(HttpException::class, 'Extension retry does not match the original actor and reason.');

    $facts = DB::table('hr_payroll_input_facts')->where('company_id', $company->id)->where('staff_id', $staff->id)
        ->where('source_type', 'leave_extension')->get();
    expect($facts)->toHaveCount(2)
        ->and($facts->pluck('source_id')->unique())->toHaveCount(2)
        ->and(DB::table('hr_leave_request_events')->whereIn('id', $facts->pluck('source_id')->all())
            ->where('leave_request_id', $requestId)->where('event_type', 'extended')->count())->toBe(2)
        ->and(DB::table('hr_leave_requests')->where('id', $requestId)->value('end_date'))->toBe('2026-10-06');
});

it('does not let a foreign leave assignment block assignment in the selected company', function () {
    [$user, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $foreignCompany = Company::create(['name' => 'Foreign Assignment Overlap', 'is_default' => false]);
    $typeId = (string) Str::uuid();
    $policyId = (string) Str::uuid();
    $now = now();

    DB::table('hr_leave_types')->insert([
        'id' => $typeId, 'company_id' => $company->id, 'code' => 'ASSIGNMENT-OVERLAP', 'name' => 'Annual leave',
        'category' => 'annual', 'unit' => 'day', 'paid' => true, 'effective_from' => '2026-01-01',
        'status' => 'active', 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_policies')->insert([
        'id' => $policyId, 'company_id' => $company->id, 'leave_type_id' => $typeId, 'code' => 'ASSIGNMENT-OVERLAP-1',
        'version' => 1, 'rules' => json_encode(['minutes_per_day' => 480]), 'effective_from' => '2026-01-01',
        'status' => 'approved', 'created_by' => $user->id, 'approved_by' => $user->id,
        'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $foreignPolicyId = (string) Str::uuid();
    DB::table('hr_leave_policies')->insert([
        'id' => $foreignPolicyId, 'company_id' => $foreignCompany->id, 'leave_type_id' => $typeId, 'code' => 'FOREIGN-ASSIGNMENT-OVERLAP-1',
        'version' => 1, 'rules' => json_encode(['minutes_per_day' => 480]), 'effective_from' => '2026-01-01',
        'status' => 'approved', 'created_by' => $user->id, 'approved_by' => $user->id,
        'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_policy_assignments')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $foreignCompany->id, 'staff_id' => $staff->id,
        'policy_id' => $policyId, 'effective_from' => '2026-10-01', 'reason' => 'Malformed foreign assignment',
        'created_by' => $user->id, 'approved_by' => $user->id, 'approved_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_policy_assignments')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id,
        'policy_id' => $foreignPolicyId, 'effective_from' => '2026-10-01', 'reason' => 'Malformed foreign policy link',
        'created_by' => $user->id, 'approved_by' => $user->id, 'approved_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    actingAs($user, 'api')->postJson('/api/hr/workforce/leave/policy-assignments', [
        'company_id' => $company->id, 'staff_id' => $staff->id, 'policy_id' => $policyId,
        'effective_from' => '2026-10-01', 'reason' => 'Selected company assignment',
    ])->assertCreated()->assertJsonPath('data.company_id', $company->id);

    expect(DB::table('hr_leave_policy_assignments')->where('company_id', $company->id)->where('staff_id', $staff->id)->where('policy_id', $policyId)->count())->toBe(1);
});
