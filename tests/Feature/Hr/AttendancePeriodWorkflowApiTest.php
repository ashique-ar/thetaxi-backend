<?php

use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('derives the period company, versions transitions, and separates manage from reopen permission', function () {
    [$admin, $company] = hr_seed_admin_actor();
    config(['hr.features.attendance_results' => true]);
    $periodPayload = ['period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'timezone' => 'Asia/Colombo'];

    $created = actingAs($admin, 'api')->postJson('/api/hr/attendance/periods', $periodPayload)
        ->assertCreated()->assertJsonPath('data.company_id', $company->id)->json('data');
    $periodId = $created['id'];
    $this->assertDatabaseHas('activity_log', ['log_name' => 'hr-attendance', 'description' => 'attendance_period_created']);
    actingAs($admin, 'api')->postJson('/api/hr/attendance/periods', [
        'period_start' => '2026-06-15', 'period_end' => '2026-07-15', 'timezone' => 'Asia/Colombo',
    ])->assertStatus(409);

    actingAs($admin, 'api')->postJson("/api/hr/attendance/periods/{$periodId}/transition", [
        'action' => 'review', 'reason' => 'Reconciliation reviewed.', 'expected_version' => 1,
    ])->assertOk()->assertJsonPath('data.version', 2);
    actingAs($admin, 'api')->postJson("/api/hr/attendance/periods/{$periodId}/transition", [
        'action' => 'lock', 'reason' => 'Stale attempt.', 'expected_version' => 1,
    ])->assertStatus(409);
    actingAs($admin, 'api')->postJson("/api/hr/attendance/periods/{$periodId}/transition", [
        'action' => 'lock', 'reason' => 'Reviewer cannot lock own review.', 'expected_version' => 2,
    ])->assertStatus(409);

    $actorWith = function (string $permission) use ($company): User {
        $user = User::factory()->create();
        $user->givePermissionTo($permission);
        $staff = Staff::factory()->create(['user_id' => $user->id, 'company_id' => $company->id]);
        UserContext::create([
            'user_id' => $user->id, 'context_type' => 'staff', 'context_id' => $staff->id,
            'is_active' => true, 'created_user_id' => $user->id,
        ]);
        return $user;
    };
    $manager = $actorWith('hr.attendance.periods.manage');
    $adminStaff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $now = now();
    $resultId = (string) Str::uuid();
    $exceptionId = (string) Str::uuid();
    DB::table('hr_attendance_daily_results')->insert([
        'id' => $resultId, 'company_id' => $company->id, 'staff_id' => $adminStaff->id, 'work_date' => '2026-06-15',
        'result_version' => 1, 'period_id' => $periodId, 'day_status' => 'absent', 'worked_minutes' => 0,
        'late_minutes' => 0, 'early_leave_minutes' => 0, 'payable_minutes' => 0, 'source_kind' => 'calculated',
        'calculated_at' => $now, 'input_checksum' => str_repeat('a', 64), 'result_checksum' => str_repeat('b', 64),
        'rule_snapshot' => json_encode([], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_attendance_exceptions')->insert([
        'id' => $exceptionId, 'company_id' => $company->id, 'staff_id' => $adminStaff->id, 'daily_result_id' => $resultId,
        'exception_type' => 'absent', 'severity' => 'high', 'status' => 'open', 'evidence' => json_encode(['day_status' => 'absent'], JSON_THROW_ON_ERROR),
        'created_at' => $now, 'updated_at' => $now,
    ]);
    actingAs($manager, 'api')->postJson("/api/hr/attendance/periods/{$periodId}/transition", [
        'action' => 'lock', 'reason' => 'Unresolved high exception.', 'expected_version' => 2,
    ])->assertStatus(409);
    actingAs($admin, 'api')->postJson("/api/hr/attendance/exceptions/{$exceptionId}/resolve", [
        'resolution_note' => 'Reviewed source evidence.',
    ])->assertOk();
    actingAs($manager, 'api')->postJson("/api/hr/attendance/periods/{$periodId}/transition", [
        'action' => 'lock', 'reason' => 'Independent lock review.', 'expected_version' => 2,
    ])->assertOk()->assertJsonPath('data.version', 3);

    $reopener = $actorWith('hr.attendance.periods.reopen');
    actingAs($reopener, 'api')->postJson("/api/hr/attendance/periods/{$periodId}/transition", [
        'action' => 'reopen', 'reason' => 'Approved recalculation required.', 'expected_version' => 3,
    ])->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.version', 4);
    actingAs($reopener, 'api')->postJson("/api/hr/attendance/periods/{$periodId}/transition", [
        'action' => 'review', 'reason' => 'Wrong permission for review.', 'expected_version' => 4,
    ])->assertForbidden();
    expect(DB::table('hr_attendance_period_events')->where('period_id', $periodId)->count())->toBe(3);
});
