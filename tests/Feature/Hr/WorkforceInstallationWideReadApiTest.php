<?php

use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('shows workforce records across locations while preserving response privacy and approval permissions', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();

    $user = User::factory()->create();
    $role = Role::create(['name' => 'workforce_installation_wide_reader', 'guard_name' => 'api']);
    $role->givePermissionTo(['hr.leave.view', 'hr.work-requests.view', 'hr.timesheets.view', 'hr.timesheets.approve', 'staff.view-all']);
    $user->assignRole($role);
    $staff = Staff::factory()->create(['user_id' => $user->id]);
    $otherStaff = Staff::factory()->create();

    $leaveTypeId = (string) Str::uuid();
    DB::table('hr_leave_types')->insert([
        'id' => $leaveTypeId, 'code' => 'INSTALLATION-LEAVE', 'name' => 'Annual leave',
        'category' => 'annual', 'unit' => 'day', 'paid' => true, 'medical_confidential' => false,
        'effective_from' => today()->toDateString(), 'status' => 'active', 'created_by' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $leavePolicyId = (string) Str::uuid();
    DB::table('hr_leave_policies')->insert([
        'id' => $leavePolicyId, 'leave_type_id' => $leaveTypeId, 'code' => 'INSTALLATION-POLICY',
        'version' => 1, 'rules' => '{}', 'effective_from' => today()->toDateString(), 'status' => 'approved',
        'created_by' => $user->id, 'approved_by' => $user->id, 'approved_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $leaveRequestIds = [];
    $balanceIds = [];
    foreach ([$staff, $otherStaff] as $employee) {
        $requestId = (string) Str::uuid();
        DB::table('hr_leave_requests')->insert([
            'id' => $requestId, 'staff_id' => $employee->id, 'leave_type_id' => $leaveTypeId,
            'policy_id' => $leavePolicyId, 'start_date' => today()->toDateString(), 'end_date' => today()->toDateString(),
            'unit' => 'day', 'requested_minutes' => 480, 'reserved_minutes' => 480, 'status' => 'approved',
            'reason' => 'Annual leave request.', 'calculation_snapshot' => '{}', 'request_checksum' => str_repeat('a', 64),
            'idempotency_key' => (string) Str::uuid(), 'requested_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $leaveRequestIds[] = $requestId;

        $balanceId = (string) Str::uuid();
        DB::table('hr_leave_balance_accounts')->insert([
            'id' => $balanceId, 'staff_id' => $employee->id, 'leave_type_id' => $leaveTypeId,
            'unit' => 'day', 'opened_at' => today()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $balanceIds[] = $balanceId;
    }

    $workPolicyId = (string) Str::uuid();
    DB::table('hr_work_request_policies')->insert([
        'id' => $workPolicyId, 'request_kind' => 'overtime', 'code' => 'INSTALLATION-OVERTIME', 'version' => 1,
        'rules' => '{}', 'effective_from' => today()->toDateString(), 'status' => 'approved',
        'created_by' => $user->id, 'approved_by' => $user->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $workRequestIds = [];
    foreach ([$staff, $otherStaff] as $employee) {
        $requestId = (string) Str::uuid();
        DB::table('hr_work_requests')->insert([
            'id' => $requestId, 'staff_id' => $employee->id, 'policy_id' => $workPolicyId,
            'request_kind' => 'overtime', 'starts_at' => now()->toDateTimeString(), 'ends_at' => now()->addHour()->toDateTimeString(),
            'requested_minutes' => 60, 'settlement_kind' => 'pay', 'status' => 'pending_approval',
            'reason' => 'Approved overtime request.', 'request_snapshot' => '{}', 'request_checksum' => str_repeat('b', 64),
            'idempotency_key' => (string) Str::uuid(), 'requested_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $workRequestIds[] = $requestId;
    }

    $timesheetId = (string) Str::uuid();
    DB::table('hr_timesheets')->insert([
        'id' => $timesheetId, 'staff_id' => $staff->id,
        'period_start' => today()->startOfMonth()->toDateString(), 'period_end' => today()->endOfMonth()->toDateString(),
        'status' => 'submitted', 'version' => 2, 'content_checksum' => str_repeat('c', 64),
        'reconciliation_snapshot' => json_encode(['private' => 'reconciliation evidence']),
        'submitted_by' => User::factory()->create()->id, 'submitted_at' => now(), 'decision_note' => 'private reviewer note',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $leaveRequests = actingAs($user, 'api')->getJson('/api/hr/workforce/leave/requests')->assertOk()->json('data.data');
    $balances = actingAs($user, 'api')->getJson('/api/hr/workforce/leave/balances')->assertOk()->json('data');
    $calendar = actingAs($user, 'api')->getJson('/api/hr/workforce/leave/team-calendar?' . http_build_query([
        'from' => today()->toDateString(), 'to' => today()->toDateString(),
    ]))->assertOk()->json('data');
    $workRequests = actingAs($user, 'api')->getJson('/api/hr/workforce/work-requests')->assertOk()->json('data.data');
    $timesheets = actingAs($user, 'api')->getJson('/api/hr/workforce/timesheets')->assertOk()->json('data.data');
    config(['hr.features.leave_overtime' => true]);
    $transition = actingAs($user, 'api')->postJson('/api/hr/workforce/timesheets/'.$timesheetId.'/transition', [
        'action' => 'approve', 'reason' => 'Review complete.',
    ])->assertOk();

    expect(collect($leaveRequests)->pluck('id')->all())->toContain(...$leaveRequestIds)
        ->and(collect($balances)->pluck('id')->all())->toContain(...$balanceIds)
        ->and(collect($calendar)->pluck('id')->all())->toContain(...$leaveRequestIds)
        ->and(collect($workRequests)->pluck('id')->all())->toContain(...$workRequestIds);
    $ownLeave = collect($leaveRequests)->firstWhere('id', $leaveRequestIds[0]);
    $ownWork = collect($workRequests)->firstWhere('id', $workRequestIds[0]);
    expect(array_keys($ownLeave))->toBe(['id', 'leave_type_name', 'start_date', 'end_date', 'requested_minutes', 'status', 'is_mine', 'actual_return_date', 'recalled_at']);
    expect($ownLeave['is_mine'])->toBeTrue();
    expect(array_keys($ownWork))->toBe(['id', 'request_kind', 'starts_at', 'ends_at', 'requested_minutes', 'settlement_kind', 'status', 'is_mine']);
    expect($ownWork['is_mine'])->toBeTrue();
    expect(array_keys($timesheets[0]))->toBe(['id', 'period_start', 'period_end', 'status', 'version', 'submitted_at', 'can_decide']);
    expect($timesheets[0])->toMatchArray(['id' => $timesheetId, 'status' => 'submitted', 'version' => 2, 'can_decide' => true]);
    expect($transition->json('data'))->toBe(['id' => $timesheetId, 'status' => 'approved', 'version' => 3]);
});
