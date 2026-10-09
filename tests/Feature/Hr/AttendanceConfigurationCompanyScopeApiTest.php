<?php

use App\Models\Company;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\UserContext;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('scopes attendance configuration and rosters to the selected authorized company', function () {
    [$user, $company] = hr_seed_admin_actor([], true);
    $otherCompany = Company::create(['name' => 'Second Configuration Company']);
    $unassignedCompany = Company::create(['name' => 'Unassigned Configuration Company']);
    $otherStaff = Staff::factory()->create(['company_id' => $otherCompany->id]);
    $ownStaff = Staff::query()->where('user_id', $user->id)->where('company_id', $company->id)->firstOrFail();
    $ownContext = UserContext::query()->where('user_id', $user->id)->where('context_id', $ownStaff->id)->firstOrFail();
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $ownContext->id];

    foreach ([[$company->id, Staff::query()->where('user_id', $user->id)->where('company_id', $company->id)->value('id')], [$otherCompany->id, $otherStaff->id]] as [$companyId, $staffId]) {
        $calendarId = (string) Str::uuid();
        $shiftId = (string) Str::uuid();
        $policyId = (string) Str::uuid();
        DB::table('hr_work_calendars')->insert([
            'id' => $calendarId, 'company_id' => $companyId, 'code' => 'CAL', 'name' => 'Standard',
            'timezone' => 'Asia/Colombo', 'weekly_working_days' => json_encode(['monday', 'tuesday']),
            'effective_from' => '2026-01-01', 'status' => 'active', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('hr_shift_definitions')->insert([
            'id' => $shiftId, 'company_id' => $companyId, 'code' => 'DAY', 'name' => 'Day shift',
            'start_time' => '09:00', 'end_time' => '18:00', 'ends_next_day' => false,
            'unpaid_break_minutes' => 60, 'grace_in_minutes' => 10, 'grace_out_minutes' => 10,
            'minimum_half_day_minutes' => 240, 'minimum_full_day_minutes' => 480, 'timezone' => 'Asia/Colombo',
            'effective_from' => '2026-01-01', 'status' => 'active', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('hr_attendance_policies')->insert([
            'id' => $policyId, 'company_id' => $companyId, 'code' => 'BASE', 'name' => 'Base policy',
            'rules' => json_encode([]), 'effective_from' => '2026-01-01', 'status' => 'approved',
            'created_by' => $user->id, 'approved_by' => $user->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('hr_roster_assignments')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId, 'staff_id' => $staffId,
            'calendar_id' => $calendarId, 'shift_id' => $shiftId, 'policy_id' => $policyId,
            'effective_from' => '2026-10-01', 'reason' => 'Configuration scope fixture',
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $foreignCalendarId = (string) Str::uuid();
    $foreignDayId = (string) Str::uuid();
    DB::table('hr_work_calendars')->insert([
        'id' => $foreignCalendarId, 'company_id' => $unassignedCompany->id, 'code' => 'PRIVATE', 'name' => 'Private calendar',
        'timezone' => 'Asia/Colombo', 'weekly_working_days' => json_encode(['monday']), 'effective_from' => '2026-01-01',
        'status' => 'active', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_work_calendar_days')->insert([
        'id' => $foreignDayId, 'calendar_id' => $foreignCalendarId, 'calendar_date' => '2026-10-05',
        'day_type' => 'holiday', 'name' => 'Private holiday', 'paid' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $foreignStaff = Staff::factory()->create(['company_id' => $unassignedCompany->id]);
    $foreignShiftId = (string) Str::uuid();
    DB::table('hr_shift_definitions')->insert([
        'id' => $foreignShiftId, 'company_id' => $unassignedCompany->id, 'code' => 'PRIVATE', 'name' => 'Private shift',
        'start_time' => '09:00', 'end_time' => '18:00', 'ends_next_day' => false, 'unpaid_break_minutes' => 60,
        'grace_in_minutes' => 10, 'grace_out_minutes' => 10, 'minimum_half_day_minutes' => 240,
        'minimum_full_day_minutes' => 480, 'timezone' => 'Asia/Colombo', 'effective_from' => '2026-01-01',
        'status' => 'active', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $foreignPolicyId = (string) Str::uuid();
    DB::table('hr_attendance_policies')->insert([
        'id' => $foreignPolicyId, 'company_id' => $unassignedCompany->id, 'code' => 'PRIVATE', 'name' => 'Private policy',
        'rules' => json_encode([]), 'effective_from' => '2026-01-01', 'status' => 'pending_approval',
        'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $foreignRosterId = (string) Str::uuid();
    DB::table('hr_roster_assignments')->insert([
        'id' => $foreignRosterId, 'company_id' => $unassignedCompany->id, 'staff_id' => $foreignStaff->id,
        'calendar_id' => $foreignCalendarId, 'shift_id' => $foreignShiftId, 'policy_id' => $foreignPolicyId,
        'effective_from' => '2026-10-01', 'reason' => 'Private assignment', 'created_by' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($user, 'api')->withHeaders($headers)->getJson('/api/hr/attendance/calendars?company_id='.$otherCompany->id)
        ->assertForbidden();
    actingAs($user, 'api')->withHeaders($headers)->getJson('/api/hr/attendance/shifts?company_id='.$otherCompany->id)
        ->assertForbidden();
    actingAs($user, 'api')->withHeaders($headers)->getJson('/api/hr/attendance/policies?company_id='.$otherCompany->id)
        ->assertForbidden();
    actingAs($user, 'api')->withHeaders($headers)->getJson('/api/hr/attendance/rosters?company_id='.$otherCompany->id)
        ->assertForbidden();
    actingAs($user, 'api')->getJson('/api/hr/attendance/rosters')
        ->assertOk()->assertJsonPath('data.data.0.company_id', $company->id);
    actingAs($user, 'api')->withHeaders($headers)->getJson('/api/hr/attendance/rosters?company_id='.$unassignedCompany->id)
        ->assertForbidden();

    config(['hr.features.attendance_results' => true]);
    actingAs($user, 'api')->withHeaders($headers)->getJson("/api/hr/attendance/calendars/{$foreignCalendarId}/days")->assertNotFound();
    actingAs($user, 'api')->withHeaders($headers)->getJson('/api/hr/attendance/calendars/'.Str::uuid().'/days')->assertNotFound();
    $calendarPayload = [
        'code' => 'PRIVATE', 'name' => 'Changed calendar', 'timezone' => 'Asia/Colombo',
        'weekly_working_days' => ['monday'], 'effective_from' => '2026-01-01', 'status' => 'active',
    ];
    actingAs($user, 'api')->withHeaders($headers)->putJson("/api/hr/attendance/calendars/{$foreignCalendarId}", $calendarPayload)->assertNotFound();
    actingAs($user, 'api')->withHeaders($headers)->putJson('/api/hr/attendance/calendars/'.Str::uuid(), $calendarPayload)->assertNotFound();
    $dayPayload = ['calendar_date' => '2026-10-06', 'day_type' => 'working', 'name' => 'Changed day', 'paid' => true];
    actingAs($user, 'api')->withHeaders($headers)->postJson("/api/hr/attendance/calendars/{$foreignCalendarId}/days", $dayPayload)->assertNotFound();
    actingAs($user, 'api')->withHeaders($headers)->postJson('/api/hr/attendance/calendars/'.Str::uuid().'/days', $dayPayload)->assertNotFound();
    actingAs($user, 'api')->withHeaders($headers)->putJson("/api/hr/attendance/calendars/{$foreignCalendarId}/days/{$foreignDayId}", $dayPayload)->assertNotFound();
    actingAs($user, 'api')->withHeaders($headers)->putJson('/api/hr/attendance/calendars/'.Str::uuid().'/days/'.Str::uuid(), $dayPayload)->assertNotFound();
    actingAs($user, 'api')->withHeaders($headers)->putJson("/api/hr/attendance/shifts/{$foreignShiftId}", [])->assertNotFound();
    actingAs($user, 'api')->withHeaders($headers)->putJson('/api/hr/attendance/shifts/'.Str::uuid(), [])->assertNotFound();
    actingAs($user, 'api')->withHeaders($headers)->putJson("/api/hr/attendance/policies/{$foreignPolicyId}", [])->assertNotFound();
    actingAs($user, 'api')->withHeaders($headers)->putJson('/api/hr/attendance/policies/'.Str::uuid(), [])->assertNotFound();
    actingAs($user, 'api')->withHeaders($headers)->postJson("/api/hr/attendance/policies/{$foreignPolicyId}/approve", [])->assertNotFound();
    actingAs($user, 'api')->withHeaders($headers)->postJson('/api/hr/attendance/policies/'.Str::uuid().'/approve', [])->assertNotFound();
    actingAs($user, 'api')->withHeaders($headers)->putJson("/api/hr/attendance/rosters/{$foreignRosterId}", [])->assertNotFound();
    actingAs($user, 'api')->withHeaders($headers)->putJson('/api/hr/attendance/rosters/'.Str::uuid(), [])->assertNotFound();
    actingAs($user, 'api')->withHeaders($headers)->postJson("/api/hr/attendance/rosters/{$foreignRosterId}/approve", [])->assertNotFound();
    actingAs($user, 'api')->withHeaders($headers)->postJson('/api/hr/attendance/rosters/'.Str::uuid().'/approve', [])->assertNotFound();
    expect(DB::table('hr_work_calendars')->where('id', $foreignCalendarId)->value('name'))->toBe('Private calendar')
        ->and(DB::table('hr_work_calendar_days')->where('id', $foreignDayId)->value('name'))->toBe('Private holiday')
        ->and(DB::table('hr_shift_definitions')->where('id', $foreignShiftId)->value('name'))->toBe('Private shift')
        ->and(DB::table('hr_attendance_policies')->where('id', $foreignPolicyId)->value('status'))->toBe('pending_approval')
        ->and(DB::table('hr_roster_assignments')->where('id', $foreignRosterId)->value('approved_at'))->toBeNull();
});

it('allows configuration approvers to load authorized company options', function () {
    [, $company] = hr_seed_admin_actor();
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('hr.attendance.config.approve');
    $staff = Staff::factory()->create(['user_id' => $viewer->id, 'company_id' => $company->id]);
    UserContext::create([
        'user_id' => $viewer->id, 'context_type' => 'staff', 'context_id' => $staff->id,
        'is_active' => true, 'created_user_id' => $viewer->id,
    ]);

    actingAs($viewer, 'api')->getJson('/api/hr/attendance/company-options')
        ->assertOk()->assertJsonPath('data.0.value', $company->id);
});
