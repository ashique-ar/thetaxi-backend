<?php

use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('fails closed on legacy and unqualified delegation approval evidence', function () {
    hr_seed_admin_actor();
    $delegateUser = User::factory()->create();
    $requesterUser = User::factory()->create();
    $approverUser = User::factory()->create();
    $otherApproverUser = User::factory()->create();
    $delegator = Staff::factory()->create();
    $otherDelegator = Staff::factory()->create();
    $wrongTypeDelegator = Staff::factory()->create();
    $validDelegator = Staff::factory()->create();
    $delegate = Staff::factory()->create(['user_id' => $delegateUser->id]);
    $approver = Staff::factory()->create(['user_id' => $approverUser->id]);
    $otherApprover = Staff::factory()->create(['user_id' => $otherApproverUser->id]);
    $context = UserContext::create([
        'user_id' => $delegateUser->id, 'context_type' => 'staff', 'context_id' => $delegate->id,
        'is_active' => true, 'created_user_id' => $delegateUser->id,
    ]);
    $delegateUser->givePermissionTo('hr.mss.approve');
    $delegateUser->givePermissionTo('hr.leave.approve');

    $today = now()->toDateString();
    $delegationRows = [
        [(string) Str::uuid(), $delegator->id, $approverUser->id, null, ['leave']],
        [(string) Str::uuid(), $otherDelegator->id, $otherApproverUser->id, $otherApprover->id, ['leave']],
        [(string) Str::uuid(), $wrongTypeDelegator->id, $approverUser->id, $approver->id, ['exit']],
        [(string) Str::uuid(), $validDelegator->id, $approverUser->id, $approver->id, ['leave']],
    ];
    $validInboxOwner = null;
    foreach ($delegationRows as [$id, $ownerId, $approvedBy, $approvedByStaffId, $requestTypes]) {
        DB::table('hr_approval_delegations')->insert([
            'id' => $id, 'delegator_staff_id' => $ownerId,
            'delegate_staff_id' => $delegate->id, 'request_types' => json_encode($requestTypes),
            'effective_from' => $today, 'effective_until' => now()->addDays(5)->toDateString(),
            'reason' => 'Delegation for leave approvals', 'status' => 'approved', 'created_by' => $requesterUser->id,
            'approved_by' => $approvedBy, 'approved_by_staff_id' => $approvedByStaffId,
            'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('hr_request_index')->insert([
            'id' => (string) Str::uuid(), 'requester_staff_id' => $delegator->id,
            'request_type' => 'leave', 'source_type' => 'leave_request', 'source_id' => (string) Str::uuid(),
            'status' => 'pending_approval', 'summary' => 'Leave request', 'current_owner_staff_id' => $ownerId,
            'capability_snapshot' => '{}', 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($approvedByStaffId === $approver->id && $requestTypes === ['leave']) $validInboxOwner = $ownerId;
    }
    actingAs($delegateUser, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson('/api/hr/ess/approval-inbox')->assertOk()->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.current_owner_staff_id', $validInboxOwner);

    $typeId = (string) Str::uuid();
    $policyId = (string) Str::uuid();
    $requestId = (string) Str::uuid();
    $validRequestId = (string) Str::uuid();
    DB::table('hr_leave_types')->insert([
        'id' => $typeId, 'code' => 'ANNUAL', 'name' => 'Annual leave',
        'category' => 'annual', 'unit' => 'day', 'paid' => true, 'medical_confidential' => false,
        'effective_from' => $today, 'status' => 'active', 'created_by' => $requesterUser->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_leave_policies')->insert([
        'id' => $policyId, 'leave_type_id' => $typeId,
        'code' => 'ANNUAL-1', 'version' => 1, 'rules' => '{"approval_levels":1}',
        'effective_from' => $today, 'status' => 'approved', 'created_by' => $requesterUser->id,
        'approved_by' => $approverUser->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([[$requestId, $delegator->id, 'legacy-delegation-leave'], [$validRequestId, $validDelegator->id, 'valid-delegation-leave']] as [$id, $ownerId, $key]) {
        DB::table('hr_leave_requests')->insert([
            'id' => $id, 'staff_id' => $delegate->id,
            'leave_type_id' => $typeId, 'policy_id' => $policyId, 'start_date' => $today, 'end_date' => $today,
            'unit' => 'day', 'requested_minutes' => 480, 'reserved_minutes' => 480, 'status' => 'pending_approval',
            'reason' => 'Annual leave', 'calculation_snapshot' => '{"rules":{"approval_levels":1}}', 'coverage_snapshot' => 'null',
            'request_checksum' => str_repeat('a', 64), 'idempotency_key' => $key,
            'requested_by' => $requesterUser->id, 'current_approver_staff_id' => $ownerId,
            'approval_level' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    config(['hr.features.leave_overtime' => true]);

    actingAs($delegateUser, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->postJson('/api/hr/workforce/leave/requests/'.$requestId.'/decide', [
        'action' => 'approve', 'reason' => 'Approve',
    ])->assertForbidden();
    expect(DB::table('hr_leave_requests')->where('id', $requestId)->value('status'))->toBe('pending_approval')
        ->and(DB::table('hr_leave_balance_entries')->where('leave_request_id', $requestId)->count())->toBe(0);

    actingAs($delegateUser, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->postJson('/api/hr/workforce/leave/requests/'.$validRequestId.'/decide', [
        'action' => 'approve', 'reason' => 'Approve',
    ])->assertOk()->assertJsonPath('data.status', 'approved');
    expect(DB::table('hr_leave_requests')->where('id', $validRequestId)->value('status'))->toBe('approved')
        ->and(DB::table('hr_leave_balance_entries')->where('leave_request_id', $validRequestId)->count())->toBe(2);
});
