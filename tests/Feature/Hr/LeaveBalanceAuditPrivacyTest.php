<?php

use App\Models\Hr\Leave\LeaveBalanceEntry;
use App\Models\Staff;
use App\Services\Hr\Leave\LeaveWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('keeps leave balance posting details out of generic activity logs', function () {
    [$actor, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $actor->id)->firstOrFail();
    config(['hr.features.leave_overtime' => true]);
    $now = now();
    $typeId = (string) Str::uuid();
    $accountId = (string) Str::uuid();
    DB::table('hr_leave_types')->insert([
        'id' => $typeId,
        'company_id' => $company->id,
        'code' => 'AUDIT-PRIVACY',
        'name' => 'Privacy test leave',
        'category' => 'annual',
        'unit' => 'minute',
        'paid' => true,
        'effective_from' => $now->toDateString(),
        'status' => 'active',
        'created_by' => $actor->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('hr_leave_balance_accounts')->insert([
        'id' => $accountId,
        'company_id' => $company->id,
        'staff_id' => $staff->id,
        'leave_type_id' => $typeId,
        'unit' => 'minute',
        'opened_at' => $now->toDateString(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $reason = 'private-leave-balance-reason';
    $idempotencyKey = 'private-leave-balance-key';
    $entry = app(LeaveWorkflowService::class)->postBalance(
        $accountId,
        'adjustment',
        37,
        $now->toDateString(),
        $reason,
        $actor->id,
        $idempotencyKey,
    );
    $audit = DB::table('activity_log')
        ->where('subject_type', LeaveBalanceEntry::class)
        ->where('subject_id', $entry->id)
        ->value('properties');

    expect($audit)->not->toBeNull()
        ->and($audit)->toContain('adjustment')
        ->and($audit)->not->toContain($accountId)
        ->and($audit)->not->toContain((string) $staff->id)
        ->and($audit)->not->toContain((string) $actor->id)
        ->and($audit)->not->toContain($reason)
        ->and($audit)->not->toContain($idempotencyKey)
        ->and($audit)->not->toContain(hash('sha256', $reason));
});

it('requires a locked active company for manual and automated leave balance postings', function () {
    [$actor, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $actor->id)->firstOrFail();
    $typeId = (string) Str::uuid();
    $accountId = (string) Str::uuid();
    $now = now();
    DB::table('hr_leave_types')->insert([
        'id' => $typeId, 'company_id' => $company->id, 'code' => 'INACTIVE-POST', 'name' => 'Inactive company leave',
        'category' => 'annual', 'unit' => 'minute', 'paid' => true, 'effective_from' => $now->toDateString(),
        'status' => 'active', 'created_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_balance_accounts')->insert([
        'id' => $accountId, 'company_id' => $company->id, 'staff_id' => $staff->id,
        'leave_type_id' => $typeId, 'unit' => 'minute', 'opened_at' => $now->toDateString(),
        'created_at' => $now, 'updated_at' => $now,
    ]);
    config(['hr.features.leave_overtime' => true]);
    DB::table('companies')->where('id', $company->id)->update(['is_active' => false]);
    $service = app(LeaveWorkflowService::class);

    expect(fn () => $service->postBalance($accountId, 'adjustment', 30, $now->toDateString(), 'Manual correction', $actor->id, 'inactive-manual-post'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class)
        ->and(fn () => $service->postAutomatedBalance($accountId, 'accrual', 30, $now->toDateString(), null, 'policy_accrual', 'inactive-automated-post', 'Accrual', [], $actor->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(DB::table('hr_leave_balance_entries')->where('account_id', $accountId)->exists())->toBeFalse();
});
