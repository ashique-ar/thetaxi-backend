<?php

use App\Models\Staff;
use App\Services\Hr\Leave\LeaveWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('requires an approved assignment before accepting a leave request', function () {
    [$user] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $typeId = (string) Str::uuid();
    $policyId = (string) Str::uuid();
    $now = now();
    $startDate = $now->copy()->addDay()->toDateString();
    DB::table('hr_leave_types')->insert([
        'id' => $typeId, 'code' => 'ASSIGNMENT-TEST', 'name' => 'Annual leave',
        'category' => 'annual', 'unit' => 'day', 'paid' => true, 'medical_confidential' => false,
        'effective_from' => $now->toDateString(), 'status' => 'active', 'created_by' => $user->id,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_policies')->insert([
        'id' => $policyId, 'leave_type_id' => $typeId, 'code' => 'ASSIGNMENT-TEST',
        'version' => 1, 'rules' => json_encode(['weekend_days' => [], 'negative_balance_limit_minutes' => 480]),
        'effective_from' => $now->toDateString(), 'status' => 'approved',
        'created_by' => $user->id, 'approved_by' => $user->id, 'approved_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    config(['hr.features.leave_overtime' => true]);

    expect(fn () => app(LeaveWorkflowService::class)->submit([
        'staff_id' => $staff->id, 'policy_id' => $policyId,
        'start_date' => $startDate, 'end_date' => $startDate, 'unit' => 'day', 'reason' => 'Annual leave',
        'idempotency_key' => 'unassigned-leave-policy',
    ], $user->id))->toThrow(HttpException::class);
    expect(DB::table('hr_leave_requests')->where('idempotency_key', 'unassigned-leave-policy')->exists())->toBeFalse();
});

it('previews accrual for approved installation-wide assignments and rejects a missing account', function () {
    [$user] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $typeId = (string) Str::uuid();
    $policyId = (string) Str::uuid();
    $accountId = (string) Str::uuid();
    $now = now();
    DB::table('hr_leave_types')->insert([
        'id' => $typeId, 'code' => 'ANNUAL', 'name' => 'Annual leave', 'category' => 'annual',
        'unit' => 'day', 'paid' => true, 'medical_confidential' => false, 'effective_from' => '2026-01-01',
        'status' => 'active', 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_policies')->insert([
        'id' => $policyId, 'leave_type_id' => $typeId, 'code' => 'ANNUAL-1', 'version' => 1,
        'rules' => json_encode(['accrual_cadence' => 'monthly', 'accrual_day' => 1, 'accrual_minutes' => 480]),
        'effective_from' => '2026-01-01', 'status' => 'approved', 'created_by' => $user->id,
        'approved_by' => $user->id, 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_policy_assignments')->insert([
        'id' => (string) Str::uuid(), 'staff_id' => $staff->id, 'policy_id' => $policyId,
        'effective_from' => '2026-01-01', 'reason' => 'Initial assignment', 'created_by' => $user->id,
        'approved_by' => $user->id, 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_balance_accounts')->insert([
        'id' => $accountId, 'staff_id' => $staff->id, 'leave_type_id' => $typeId,
        'unit' => 'day', 'opened_at' => '2026-01-01', 'created_at' => $now, 'updated_at' => $now,
    ]);

    Artisan::call('hr:process-leave-accruals', ['--as-of' => '2026-10-01']);
    expect(Artisan::output())->toContain('Previewed 1 leave ledger operations')
        ->and(DB::table('hr_leave_balance_entries')->count())->toBe(0);

    config(['hr.features.leave_overtime' => true]);
    expect(fn () => app(LeaveWorkflowService::class)->postAutomatedBalance(
        (string) Str::uuid(), 'accrual', 480, '2026-10-01', null, 'policy_accrual', 'missing-account-source',
        'Policy accrual', [], $user->id,
    ))->toThrow(HttpException::class);
    expect(DB::table('hr_leave_balance_entries')->count())->toBe(0);
});

it('rejects automated leave replay when its account or retained evidence differs', function () {
    [$user] = hr_seed_admin_actor();
    $firstStaff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $secondStaff = Staff::factory()->create();
    $now = now();
    $typeId = (string) Str::uuid();
    DB::table('hr_leave_types')->insert([
        'id' => $typeId, 'code' => 'REPLAY-ACCRUAL', 'name' => 'Replay accrual',
        'category' => 'annual', 'unit' => 'minute', 'paid' => true, 'effective_from' => $now->toDateString(),
        'status' => 'active', 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $accountIds = [(string) Str::uuid(), (string) Str::uuid()];
    foreach ([[$accountIds[0], $firstStaff], [$accountIds[1], $secondStaff]] as [$accountId, $staff]) {
        DB::table('hr_leave_balance_accounts')->insert([
            'id' => $accountId, 'staff_id' => $staff->id, 'leave_type_id' => $typeId,
            'unit' => 'minute', 'opened_at' => $now->toDateString(), 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    config(['hr.features.leave_overtime' => true]);
    $service = app(LeaveWorkflowService::class);
    $entry = $service->postAutomatedBalance($accountIds[0], 'accrual', 120, $now->toDateString(), null, 'policy_accrual', 'same-source', 'Policy accrual', ['policy_version' => 2], $user->id);

    expect($service->postAutomatedBalance($accountIds[0], 'accrual', 120, $now->toDateString(), null, 'policy_accrual', 'same-source', 'Policy accrual', ['policy_version' => 2], $user->id)->id)
        ->toBe($entry->id)
        ->and(fn () => $service->postAutomatedBalance($accountIds[1], 'accrual', 120, $now->toDateString(), null, 'policy_accrual', 'same-source', 'Policy accrual', ['policy_version' => 2], $user->id))
        ->toThrow(HttpException::class, 'Automated leave source was reused with different evidence.')
        ->and(fn () => $service->postAutomatedBalance($accountIds[0], 'accrual', 120, $now->toDateString(), null, 'policy_accrual', 'same-source', 'Changed policy evidence', ['policy_version' => 3], $user->id))
        ->toThrow(HttpException::class, 'Automated leave source was reused with different evidence.');
    expect(DB::table('hr_leave_balance_entries')->where('source_id', 'same-source')->count())->toBe(1);
});
