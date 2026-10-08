<?php

use App\Models\Staff;
use App\Models\User;
use App\Services\Hr\Leave\LeaveWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('replays each leave approval only for its original Staff context and note', function () {
    [$firstApprover, $company] = hr_seed_admin_actor();
    $firstStaff = Staff::query()->where('user_id', $firstApprover->id)->firstOrFail();
    $secondApprover = User::factory()->create();
    $secondStaff = Staff::factory()->create(['user_id' => $secondApprover->id, 'company_id' => $company->id]);
    $requester = User::factory()->create();
    $typeId = (string) Str::uuid();
    $policyId = (string) Str::uuid();
    $requestId = (string) Str::uuid();
    $now = now();

    DB::table('hr_leave_types')->insert([
        'id' => $typeId, 'company_id' => $company->id, 'code' => 'REPLAY-LEAVE', 'name' => 'Replay leave',
        'category' => 'annual', 'unit' => 'day', 'paid' => true, 'effective_from' => $now->toDateString(),
        'status' => 'active', 'created_by' => $firstApprover->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_policies')->insert([
        'id' => $policyId, 'company_id' => $company->id, 'leave_type_id' => $typeId, 'code' => 'REPLAY-LEAVE-2',
        'version' => 1, 'rules' => json_encode(['approval_levels' => 2, 'approver_staff_ids' => [$firstStaff->id, $secondStaff->id]]),
        'effective_from' => $now->toDateString(), 'status' => 'approved', 'created_by' => $firstApprover->id,
        'approved_by' => $firstApprover->id, 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_requests')->insert([
        'id' => $requestId, 'company_id' => $company->id, 'staff_id' => $firstStaff->id, 'leave_type_id' => $typeId,
        'policy_id' => $policyId, 'start_date' => $now->addDays(7)->toDateString(), 'end_date' => $now->addDays(7)->toDateString(),
        'unit' => 'day', 'requested_minutes' => 480, 'reserved_minutes' => 480, 'status' => 'pending_approval',
        'reason' => 'Annual leave request', 'calculation_snapshot' => json_encode(['rules' => ['approval_levels' => 2, 'approver_staff_ids' => [$firstStaff->id, $secondStaff->id]]]),
        'coverage_snapshot' => 'null', 'request_checksum' => str_repeat('a', 64), 'idempotency_key' => 'leave-approval-replay',
        'requested_by' => $requester->id, 'current_approver_staff_id' => $firstStaff->id, 'approval_level' => 1,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    config(['hr.features.leave_overtime' => true]);
    $service = app(LeaveWorkflowService::class);
    $firstNote = 'Reviewed first approval level.';

    $levelTwo = $service->decide($requestId, 'approve', $firstNote, $firstApprover->id, $firstStaff->id);
    $firstReplay = $service->decide($requestId, 'approve', $firstNote, $firstApprover->id, $firstStaff->id);
    expect($levelTwo->approval_level)->toBe(2)
        ->and($firstReplay->approval_level)->toBe(2)
        ->and(DB::table('hr_leave_request_events')->where('leave_request_id', $requestId)->count())->toBe(1);

    $finalNote = 'Reviewed final approval level.';
    $approved = $service->decide($requestId, 'approve', $finalNote, $secondApprover->id, $secondStaff->id);
    $finalReplay = $service->decide($requestId, 'approve', $finalNote, $secondApprover->id, $secondStaff->id);
    expect($approved->status)->toBe('approved')
        ->and($finalReplay->status)->toBe('approved')
        ->and(DB::table('hr_leave_request_events')->where('leave_request_id', $requestId)->count())->toBe(2)
        ->and(DB::table('hr_leave_balance_entries')->where('leave_request_id', $requestId)->count())->toBe(2);

    expect(fn () => $service->decide($requestId, 'approve', $finalNote, $requester->id, $firstStaff->id))
        ->toThrow(HttpException::class)
        ->and(fn () => $service->decide($requestId, 'approve', 'Changed note.', $secondApprover->id, $secondStaff->id))
        ->toThrow(HttpException::class);
});
