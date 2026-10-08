<?php

use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('refuses roster approval until its same-company configuration is active and approved', function () {
    [$approver, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $approver->id)->firstOrFail();
    $creator = User::factory()->create();
    config(['hr.features.attendance_results' => true]);
    $now = now();
    $calendarId = (string) Str::uuid();
    $shiftId = (string) Str::uuid();
    $policyId = (string) Str::uuid();
    $rosterId = (string) Str::uuid();

    DB::table('hr_work_calendars')->insert([
        'id' => $calendarId, 'company_id' => $company->id, 'code' => 'default', 'name' => 'Default', 'timezone' => 'Asia/Colombo',
        'weekly_working_days' => json_encode(['monday'], JSON_THROW_ON_ERROR), 'effective_from' => '2026-01-01', 'status' => 'active',
        'created_by' => $approver->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_shift_definitions')->insert([
        'id' => $shiftId, 'company_id' => $company->id, 'code' => 'day', 'name' => 'Day shift', 'start_time' => '08:00:00', 'end_time' => '17:00:00',
        'ends_next_day' => false, 'unpaid_break_minutes' => 60, 'grace_in_minutes' => 0, 'grace_out_minutes' => 0,
        'minimum_half_day_minutes' => 240, 'minimum_full_day_minutes' => 480, 'timezone' => 'Asia/Colombo', 'effective_from' => '2026-01-01',
        'status' => 'active', 'created_by' => $approver->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_attendance_policies')->insert([
        'id' => $policyId, 'company_id' => $company->id, 'code' => 'standard', 'name' => 'Standard',
        'rules' => json_encode(['maximum_payable_minutes' => 480], JSON_THROW_ON_ERROR), 'effective_from' => '2026-01-01',
        'status' => 'pending_approval', 'created_by' => $approver->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_roster_assignments')->insert([
        'id' => $rosterId, 'company_id' => $company->id, 'staff_id' => $staff->id, 'calendar_id' => $calendarId,
        'shift_id' => $shiftId, 'policy_id' => $policyId, 'effective_from' => '2026-01-01', 'reason' => 'Test roster',
        'created_by' => $creator->id, 'created_at' => $now, 'updated_at' => $now,
    ]);

    actingAs($approver, 'api')->postJson("/api/hr/attendance/rosters/{$rosterId}/approve")->assertStatus(422);
    $this->assertDatabaseHas('hr_roster_assignments', ['id' => $rosterId, 'approved_at' => null]);

    DB::table('hr_attendance_policies')->where('id', $policyId)->update([
        'status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => $now,
    ]);
    actingAs($approver, 'api')->postJson("/api/hr/attendance/rosters/{$rosterId}/approve")->assertOk();
    $this->assertDatabaseHas('hr_roster_assignments', ['id' => $rosterId, 'approved_by' => $approver->id]);
    $approvedAt = DB::table('hr_roster_assignments')->where('id', $rosterId)->value('approved_at');
    actingAs($approver, 'api')->postJson("/api/hr/attendance/rosters/{$rosterId}/approve")->assertOk();
    expect(DB::table('hr_roster_assignments')->where('id', $rosterId)->value('approved_at'))->toBe($approvedAt);
    $audit = DB::table('activity_log')->where('description', 'attendance_roster_approved')->first();
    expect(json_decode($audit->properties, true))->toBe([
        'company_id' => $company->id, 'status' => 'approved', 'effective_from' => '2026-01-01',
    ])->and(DB::table('activity_log')->where('description', 'attendance_roster_approved')->count())->toBe(1);

    $otherApprover = User::factory()->create();
    $otherApprover->assignRole('admin');
    $otherApproverStaff = Staff::factory()->create([
        'user_id' => $otherApprover->id, 'company_id' => $company->id, 'staff_type' => 'admin',
    ]);
    UserContext::create([
        'user_id' => $otherApprover->id, 'context_type' => 'staff', 'context_id' => $otherApproverStaff->id,
        'is_active' => true, 'created_user_id' => $otherApprover->id,
    ]);
    actingAs($otherApprover, 'api')->postJson("/api/hr/attendance/rosters/{$rosterId}/approve")->assertStatus(409);

    $otherRosterId = (string) Str::uuid();
    $otherStaff = Staff::factory()->create(['company_id' => $company->id]);
    DB::table('hr_roster_assignments')->insert([
        'id' => $otherRosterId, 'company_id' => $company->id, 'staff_id' => $otherStaff->id,
        'calendar_id' => $calendarId, 'shift_id' => $shiftId, 'policy_id' => $policyId,
        'effective_from' => '2026-01-01', 'reason' => 'Other roster', 'created_by' => $creator->id,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    actingAs($approver, 'api')->putJson("/api/hr/attendance/rosters/{$otherRosterId}", [
        'staff_id' => $staff->id,
        'calendar_id' => $calendarId,
        'shift_id' => $shiftId,
        'policy_id' => $policyId,
        'effective_from' => '2026-01-01',
        'effective_until' => null,
        'reason' => 'Overlapping edit',
    ])->assertStatus(409);
});

it('rejects attendance configuration creates for an inactive legal entity', function () {
    [$admin, $company] = hr_seed_admin_actor();
    config(['hr.features.attendance_results' => true]);
    DB::table('companies')->where('id', $company->id)->update(['is_active' => false]);

    actingAs($admin, 'api')->postJson('/api/hr/attendance/rosters', [
        'company_id' => $company->id, 'staff_id' => (string) Str::uuid(),
        'calendar_id' => (string) Str::uuid(), 'shift_id' => (string) Str::uuid(), 'policy_id' => (string) Str::uuid(),
        'effective_from' => '2026-01-01', 'effective_until' => null, 'reason' => 'Reviewed assignment',
    ])->assertForbidden();
    actingAs($admin, 'api')->postJson('/api/hr/attendance/calendars', [
        'company_id' => $company->id, 'code' => 'inactive', 'name' => 'Inactive', 'timezone' => 'Asia/Colombo',
        'weekly_working_days' => ['monday'], 'effective_from' => '2026-01-01',
    ])->assertForbidden();
    actingAs($admin, 'api')->postJson('/api/hr/attendance/shifts', [
        'company_id' => $company->id, 'code' => 'inactive', 'name' => 'Inactive', 'start_time' => '08:00', 'end_time' => '17:00',
        'ends_next_day' => false, 'unpaid_break_minutes' => 60, 'grace_in_minutes' => 0, 'grace_out_minutes' => 0,
        'minimum_half_day_minutes' => 240, 'minimum_full_day_minutes' => 480, 'timezone' => 'Asia/Colombo', 'effective_from' => '2026-01-01',
    ])->assertForbidden();
    actingAs($admin, 'api')->postJson('/api/hr/attendance/policies', [
        'company_id' => $company->id, 'code' => 'inactive', 'name' => 'Inactive',
        'rules' => ['maximum_payable_minutes' => 480], 'effective_from' => '2026-01-01',
    ])->assertForbidden();

    $this->assertDatabaseCount('hr_roster_assignments', 0);
    $this->assertDatabaseCount('hr_work_calendars', 0);
    $this->assertDatabaseCount('hr_shift_definitions', 0);
    $this->assertDatabaseCount('hr_attendance_policies', 0);
});
