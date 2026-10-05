<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use App\Services\Hr\Leave\LeaveWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('returns the committed leave request on retry and rejects changed evidence for the same key', function () {
    [$user, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    config(['hr.features.leave_overtime' => true]);
    $today = now()->toDateString();
    $start = CarbonImmutable::now()->addDays(14)->startOfDay();
    while ($start->isWeekend()) $start = $start->addDay();

    $typeId = (string) Str::uuid();
    $policyId = (string) Str::uuid();
    DB::table('hr_leave_types')->insert([
        'id' => $typeId, 'company_id' => $company->id, 'code' => 'ANNUAL', 'name' => 'Annual leave',
        'category' => 'annual', 'unit' => 'day', 'paid' => true, 'medical_confidential' => false,
        'effective_from' => $today, 'status' => 'active', 'created_by' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_leave_policies')->insert([
        'id' => $policyId, 'company_id' => $company->id, 'leave_type_id' => $typeId,
        'code' => 'ANNUAL-1', 'version' => 1,
        'rules' => json_encode(['minutes_per_day' => 480, 'weekend_days' => ['saturday', 'sunday'], 'negative_balance_limit_minutes' => 480]),
        'effective_from' => $today, 'status' => 'approved', 'created_by' => $user->id,
        'approved_by' => $user->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_leave_policy_assignments')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id,
        'policy_id' => $policyId, 'effective_from' => $today, 'reason' => 'Initial assignment',
        'created_by' => $user->id, 'approved_by' => $user->id, 'approved_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $payload = [
        'company_id' => $company->id, 'staff_id' => $staff->id, 'policy_id' => $policyId,
        'start_date' => $start->toDateString(), 'end_date' => $start->toDateString(),
        'unit' => 'day', 'reason' => 'Annual leave', 'idempotency_key' => 'leave-retry-1',
    ];
    $service = app(LeaveWorkflowService::class);
    $first = $service->submit($payload, $user->id);
    $retry = $service->submit($payload, $user->id);

    expect($retry->id)->toBe($first->id)
        ->and(DB::table('hr_leave_requests')->where('idempotency_key', $payload['idempotency_key'])->count())->toBe(1)
        ->and(DB::table('hr_leave_request_days')->where('leave_request_id', $first->id)->count())->toBe(1)
        ->and(DB::table('hr_leave_request_events')->where('leave_request_id', $first->id)->count())->toBe(1)
        ->and(DB::table('hr_leave_balance_entries')->where('leave_request_id', $first->id)->count())->toBe(1);

    expect(fn () => $service->submit(array_replace($payload, ['reason' => 'Changed evidence']), $user->id))
        ->toThrow(HttpException::class);
    expect(fn () => $service->submit($payload, User::factory()->create()->id))
        ->toThrow(HttpException::class);

    DB::table('hr_leave_policies')->where('id', $policyId)->update([
        'rules' => json_encode(['minutes_per_day' => 480, 'weekend_days' => ['saturday', 'sunday'], 'negative_balance_limit_minutes' => 1440], JSON_THROW_ON_ERROR),
    ]);
    $nextStart = $start->addWeek();
    $otherCompany = Company::create(['name' => 'Other Leave Request Company', 'is_default' => false]);
    DB::table('hr_leave_requests')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $otherCompany->id, 'staff_id' => $staff->id,
        'leave_type_id' => $typeId, 'policy_id' => $policyId, 'start_date' => $nextStart->toDateString(), 'end_date' => $nextStart->toDateString(),
        'unit' => 'day', 'requested_minutes' => 480, 'reserved_minutes' => 480, 'status' => 'pending_approval',
        'reason' => 'Foreign tenant overlap', 'calculation_snapshot' => '{}', 'coverage_snapshot' => 'null',
        'request_checksum' => str_repeat('c', 64), 'idempotency_key' => 'foreign-leave-overlap', 'requested_by' => $user->id,
        'approval_level' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $scopedRequest = $service->submit(array_replace($payload, [
        'start_date' => $nextStart->toDateString(), 'end_date' => $nextStart->toDateString(),
        'reason' => 'Selected company leave request.', 'idempotency_key' => 'selected-company-leave-overlap',
    ]), $user->id);
    expect($scopedRequest->company_id)->toBe($company->id)
        ->and($scopedRequest->status)->toBe('pending_approval');
});
