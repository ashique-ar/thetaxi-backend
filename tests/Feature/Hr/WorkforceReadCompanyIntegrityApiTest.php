<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('excludes leave and work records whose company disagrees with the linked Staff company', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();

    $user = User::factory()->create();
    $role = Role::create(['name' => 'workforce_read_company_integrity_tester', 'guard_name' => 'api']);
    $role->givePermissionTo(['hr.leave.view', 'hr.work-requests.view', 'hr.timesheets.view', 'hr.timesheets.approve', 'staff.view-all']);
    $user->assignRole($role);

    $company = Company::create(['name' => 'Workforce Record Company', 'is_active' => true, 'is_default' => true]);
    $otherCompany = Company::create(['name' => 'Other Workforce Record Company', 'is_active' => true, 'is_default' => false]);
    $staff = Staff::factory()->create(['user_id' => $user->id, 'company_id' => $company->id]);
    $otherStaff = Staff::factory()->create(['company_id' => $otherCompany->id]);

    $createLeaveType = function (Company $owner, string $code) use ($user): string {
        $id = (string) Str::uuid();
        DB::table('hr_leave_types')->insert([
            'id' => $id, 'company_id' => $owner->id, 'code' => $code, 'name' => $code,
            'category' => 'annual', 'unit' => 'day', 'paid' => true, 'medical_confidential' => false,
            'effective_from' => today()->toDateString(), 'status' => 'active', 'created_by' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };
    $leaveType = $createLeaveType($company, 'LOCAL-LEAVE');
    $otherLeaveType = $createLeaveType($otherCompany, 'OTHER-LEAVE');

    $createLeavePolicy = function (Company $owner, string $typeId, string $code) use ($user): string {
        $id = (string) Str::uuid();
        DB::table('hr_leave_policies')->insert([
            'id' => $id, 'company_id' => $owner->id, 'leave_type_id' => $typeId, 'code' => $code,
            'version' => 1, 'rules' => '{}', 'effective_from' => today()->toDateString(), 'status' => 'approved',
            'created_by' => $user->id, 'approved_by' => $user->id, 'approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };
    $leavePolicy = $createLeavePolicy($company, $leaveType, 'LOCAL-POLICY');
    $otherLeavePolicy = $createLeavePolicy($otherCompany, $otherLeaveType, 'OTHER-POLICY');

    $createLeaveRequest = function (string $companyId, string $staffId, string $typeId, string $policyId) use ($user): string {
        $id = (string) Str::uuid();
        DB::table('hr_leave_requests')->insert([
            'id' => $id, 'company_id' => $companyId, 'staff_id' => $staffId, 'leave_type_id' => $typeId,
            'policy_id' => $policyId, 'start_date' => today()->toDateString(), 'end_date' => today()->toDateString(),
            'unit' => 'day', 'requested_minutes' => 480, 'reserved_minutes' => 480, 'status' => 'approved',
            'reason' => 'Annual leave request.', 'calculation_snapshot' => '{}', 'request_checksum' => str_repeat('a', 64),
            'idempotency_key' => (string) Str::uuid(), 'requested_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };
    $localLeaveRequest = $createLeaveRequest($company->id, $staff->id, $leaveType, $leavePolicy);
    $otherLeaveRequest = $createLeaveRequest($otherCompany->id, $otherStaff->id, $otherLeaveType, $otherLeavePolicy);
    $mismatchedLeaveRequest = $createLeaveRequest($otherCompany->id, $staff->id, $otherLeaveType, $otherLeavePolicy);

    $createBalanceAccount = function (string $companyId, string $staffId, string $typeId): string {
        $id = (string) Str::uuid();
        DB::table('hr_leave_balance_accounts')->insert([
            'id' => $id, 'company_id' => $companyId, 'staff_id' => $staffId, 'leave_type_id' => $typeId,
            'unit' => 'day', 'opened_at' => today()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };
    $localBalance = $createBalanceAccount($company->id, $staff->id, $leaveType);
    $otherBalance = $createBalanceAccount($otherCompany->id, $otherStaff->id, $otherLeaveType);
    $mismatchedBalance = $createBalanceAccount($otherCompany->id, $staff->id, $otherLeaveType);

    $createWorkPolicy = function (Company $owner, string $code) use ($user): string {
        $id = (string) Str::uuid();
        DB::table('hr_work_request_policies')->insert([
            'id' => $id, 'company_id' => $owner->id, 'request_kind' => 'overtime', 'code' => $code,
            'version' => 1, 'rules' => '{}', 'effective_from' => today()->toDateString(), 'status' => 'approved',
            'created_by' => $user->id, 'approved_by' => $user->id, 'approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };
    $workPolicy = $createWorkPolicy($company, 'LOCAL-WORK');
    $otherWorkPolicy = $createWorkPolicy($otherCompany, 'OTHER-WORK');
    $createWorkRequest = function (string $companyId, string $staffId, string $policyId) use ($user): string {
        $id = (string) Str::uuid();
        DB::table('hr_work_requests')->insert([
            'id' => $id, 'company_id' => $companyId, 'staff_id' => $staffId, 'policy_id' => $policyId,
            'request_kind' => 'overtime', 'starts_at' => now()->toDateTimeString(), 'ends_at' => now()->addHour()->toDateTimeString(),
            'requested_minutes' => 60, 'settlement_kind' => 'pay', 'status' => 'pending_approval',
            'reason' => 'Approved overtime request.', 'request_snapshot' => '{}', 'request_checksum' => str_repeat('b', 64),
            'idempotency_key' => (string) Str::uuid(), 'requested_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };
    $localWorkRequest = $createWorkRequest($company->id, $staff->id, $workPolicy);
    $otherWorkRequest = $createWorkRequest($otherCompany->id, $otherStaff->id, $otherWorkPolicy);
    $mismatchedWorkRequest = $createWorkRequest($otherCompany->id, $staff->id, $otherWorkPolicy);

    $submitter = User::factory()->create();
    $timesheetId = (string) Str::uuid();
    DB::table('hr_timesheets')->insert([
        'id' => $timesheetId, 'company_id' => $company->id, 'staff_id' => $staff->id,
        'period_start' => today()->startOfMonth()->toDateString(), 'period_end' => today()->endOfMonth()->toDateString(),
        'status' => 'submitted', 'version' => 2, 'content_checksum' => str_repeat('c', 64),
        'reconciliation_snapshot' => json_encode(['private' => 'reconciliation evidence']),
        'submitted_by' => $submitter->id, 'submitted_at' => now(), 'decision_note' => 'private reviewer note',
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

    expect(collect($leaveRequests)->pluck('id')->all())->toContain($localLeaveRequest);
    expect(collect($leaveRequests)->pluck('id')->all())->toContain($otherLeaveRequest);
    expect(collect($leaveRequests)->pluck('id')->all())->not->toContain($mismatchedLeaveRequest);
    expect(collect($balances)->pluck('id')->all())->toContain($localBalance);
    expect(collect($balances)->pluck('id')->all())->toContain($otherBalance);
    expect(collect($balances)->pluck('id')->all())->not->toContain($mismatchedBalance);
    expect(collect($calendar)->pluck('id')->all())->toContain($localLeaveRequest);
    expect(collect($calendar)->pluck('id')->all())->toContain($otherLeaveRequest);
    expect(collect($calendar)->pluck('id')->all())->not->toContain($mismatchedLeaveRequest);
    expect(collect($workRequests)->pluck('id')->all())->toContain($localWorkRequest);
    expect(collect($workRequests)->pluck('id')->all())->toContain($otherWorkRequest);
    expect(collect($workRequests)->pluck('id')->all())->not->toContain($mismatchedWorkRequest);
    $ownLeave = collect($leaveRequests)->firstWhere('id', $localLeaveRequest);
    $ownWork = collect($workRequests)->firstWhere('id', $localWorkRequest);
    expect(array_keys($ownLeave))->toBe(['id', 'leave_type_name', 'start_date', 'end_date', 'requested_minutes', 'status', 'is_mine', 'actual_return_date', 'recalled_at']);
    expect($ownLeave['is_mine'])->toBeTrue();
    expect(array_keys($ownWork))->toBe(['id', 'request_kind', 'starts_at', 'ends_at', 'requested_minutes', 'settlement_kind', 'status', 'is_mine']);
    expect($ownWork['is_mine'])->toBeTrue();
    expect(array_keys($timesheets[0]))->toBe(['id', 'period_start', 'period_end', 'status', 'version', 'submitted_at', 'can_decide']);
    expect($timesheets[0])->toMatchArray(['id' => $timesheetId, 'status' => 'submitted', 'version' => 2, 'can_decide' => true]);
    expect($transition->json('data'))->toBe(['id' => $timesheetId, 'status' => 'approved', 'version' => 3]);
});
