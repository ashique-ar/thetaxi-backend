<?php

use App\Models\Hr\Attendance\AttendanceConnector;
use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use App\Services\Hr\Attendance\AttendanceResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('restricts direct attendance calculation to the actor Staff scope within their company', function () {
    [$actor, $company] = hr_seed_admin_actor();
    $adminRole = Role::findByName('admin', 'api');
    foreach (['staff.view-all', 'staff.view-legal-entity', 'staff.view-team'] as $permission) {
        $adminRole->revokePermissionTo($permission);
    }
    $otherStaff = Staff::factory()->create(['company_id' => $company->id]);
    config(['hr.features.attendance_results' => true]);
    $context = UserContext::query()->where('user_id', $actor->id)->where('context_type', 'staff')->firstOrFail();

    actingAs($actor, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->postJson('/api/hr/attendance/results/calculate', [
        'company_id' => $company->id, 'staff_id' => $otherStaff->id, 'work_date' => '2026-10-01',
    ])->assertNotFound();

    expect(DB::table('hr_attendance_daily_results')->where('staff_id', $otherStaff->id)->exists())->toBeFalse();
});

it('locks the company-scoped Staff row before assigning an attendance result version', function () {
    $service = file_get_contents(app_path('Services/Hr/Attendance/AttendanceResultService.php'));
    $staffLock = strpos($service, "DB::table('staff')->where('id', \$staffId)->where('company_id', \$companyId)->lockForUpdate()->first()");
    $resultLock = strpos($service, "latest('result_version')->lockForUpdate()->first()");

    expect(is_int($staffLock))->toBeTrue()
        ->and(is_int($resultLock) && $resultLock > $staffLock)->toBeTrue();
});

it('uses mapped or company-scoped resolved events and ignores quarantined rows', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    config(['hr.features.attendance_results' => true]);
    $now = now();
    $calendarId = (string) Str::uuid();
    $shiftId = (string) Str::uuid();
    $policyId = (string) Str::uuid();
    $rosterId = (string) Str::uuid();

    DB::table('hr_work_calendars')->insert([
        'id' => $calendarId, 'company_id' => $company->id, 'code' => 'test', 'name' => 'Test calendar', 'timezone' => 'Asia/Colombo',
        'weekly_working_days' => json_encode(['monday'], JSON_THROW_ON_ERROR), 'effective_from' => '2026-01-01', 'status' => 'active',
        'created_by' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_shift_definitions')->insert([
        'id' => $shiftId, 'company_id' => $company->id, 'code' => 'test', 'name' => 'Test shift', 'start_time' => '08:00:00', 'end_time' => '17:00:00',
        'ends_next_day' => false, 'unpaid_break_minutes' => 0, 'grace_in_minutes' => 0, 'grace_out_minutes' => 0,
        'minimum_half_day_minutes' => 240, 'minimum_full_day_minutes' => 480, 'timezone' => 'Asia/Colombo', 'effective_from' => '2026-01-01',
        'status' => 'active', 'created_by' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_attendance_policies')->insert([
        'id' => $policyId, 'company_id' => $company->id, 'code' => 'test', 'name' => 'Test policy',
        'rules' => json_encode(['maximum_payable_minutes' => 540], JSON_THROW_ON_ERROR), 'effective_from' => '2026-01-01',
        'status' => 'approved', 'created_by' => $admin->id, 'approved_by' => $admin->id, 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_roster_assignments')->insert([
        'id' => $rosterId, 'company_id' => $company->id, 'staff_id' => $staff->id, 'calendar_id' => $calendarId,
        'shift_id' => $shiftId, 'policy_id' => $policyId, 'effective_from' => '2026-01-01', 'reason' => 'Test roster',
        'created_by' => $admin->id, 'approved_by' => $admin->id, 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $connector = AttendanceConnector::factory()->create(['company_id' => $company->id, 'created_user_id' => $admin->id]);
    $device = AttendanceDevice::factory()->create(['company_id' => $company->id, 'connector_id' => $connector->id, 'created_user_id' => $admin->id]);
    $requestId = (string) Str::uuid();
    DB::table('hr_attendance_ingestion_requests')->insert([
        'id' => $requestId, 'connector_id' => $connector->id, 'device_id' => $device->id, 'request_id' => 'eligibility-test', 'nonce' => 'eligibility-test',
        'signed_at' => $now, 'received_at' => $now, 'payload_checksum' => str_repeat('a', 64), 'event_count' => 2,
        'status' => 'accepted', 'created_at' => $now, 'updated_at' => $now,
    ]);

    $mappedOutId = (string) Str::uuid();
    $quarantinedInId = (string) Str::uuid();
    foreach ([[$mappedOutId, 'out', 'mapped', $staff->id], [$quarantinedInId, 'in', 'quarantined', $staff->id]] as [$id, $direction, $mappingStatus, $eventStaffId]) {
        DB::table('hr_attendance_raw_events')->insert([
            'id' => $id, 'company_id' => $company->id, 'connector_id' => $connector->id, 'device_id' => $device->id, 'ingestion_request_id' => $requestId,
            'staff_id' => $eventStaffId, 'provider_event_id' => $id, 'provider_person_id' => 'person-1', 'occurred_at' => $direction === 'in' ? '2026-06-01 02:30:00' : '2026-06-01 11:30:00',
            'source_timezone' => 'Asia/Colombo', 'source_utc_offset_minutes' => 330, 'event_kind' => 'punch', 'direction' => $direction,
            'verification_result' => 'accepted', 'encrypted_raw_payload' => '{}', 'payload_checksum' => str_repeat($direction === 'in' ? 'b' : 'c', 64),
            'mapping_status' => $mappingStatus, 'received_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    $quarantineId = (string) Str::uuid();
    DB::table('hr_attendance_quarantine_items')->insert([
        'id' => $quarantineId, 'company_id' => $company->id, 'raw_event_id' => $quarantinedInId, 'reason_code' => 'unmatched_person',
        'details' => 'Awaiting verified resolution', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
    ]);

    $otherCompany = Company::create(['name' => 'Other attendance tenant', 'is_default' => false]);
    $foreignLeaveTypeId = (string) Str::uuid();
    DB::table('hr_leave_types')->insert([
        'id' => $foreignLeaveTypeId, 'company_id' => $otherCompany->id, 'code' => 'FOREIGN-PAID', 'name' => 'Foreign paid leave',
        'category' => 'annual', 'unit' => 'minutes', 'paid' => true, 'effective_from' => '2026-01-01', 'status' => 'active',
        'created_by' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $foreignLeavePolicyId = (string) Str::uuid();
    DB::table('hr_leave_policies')->insert([
        'id' => $foreignLeavePolicyId, 'company_id' => $otherCompany->id, 'leave_type_id' => $foreignLeaveTypeId,
        'code' => 'FOREIGN-PAID', 'version' => 1, 'rules' => '{}', 'effective_from' => '2026-01-01', 'status' => 'approved',
        'created_by' => $admin->id, 'approved_by' => $admin->id, 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $foreignLeaveRequestId = (string) Str::uuid();
    DB::table('hr_leave_requests')->insert([
        'id' => $foreignLeaveRequestId, 'company_id' => $otherCompany->id, 'staff_id' => $staff->id,
        'leave_type_id' => $foreignLeaveTypeId, 'policy_id' => $foreignLeavePolicyId, 'start_date' => '2026-06-01',
        'end_date' => '2026-06-01', 'unit' => 'minutes', 'requested_minutes' => 480, 'reserved_minutes' => 480,
        'status' => 'approved', 'reason' => 'Foreign tenant fixture', 'calculation_snapshot' => '{}',
        'request_checksum' => str_repeat('d', 64), 'idempotency_key' => (string) Str::uuid(), 'requested_by' => $admin->id,
        'decided_at' => $now, 'decided_by' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_request_days')->insert([
        'id' => (string) Str::uuid(), 'leave_request_id' => $foreignLeaveRequestId, 'leave_date' => '2026-06-01',
        'minutes' => 480, 'day_kind' => 'full_day', 'rule_evidence' => '{}', 'created_at' => $now, 'updated_at' => $now,
    ]);

    $service = app(AttendanceResultService::class);
    $first = $service->calculate($company->id, $staff->id, '2026-06-01', $admin->id);
    expect($first->worked_minutes)->toBe(0)
        ->and($first->payable_minutes)->toBe(0)
        ->and($first->day_status)->toBe('incomplete')
        ->and($first->first_in_at)->toBeNull()
        ->and(DB::table('hr_attendance_daily_result_sources')->where('daily_result_id', $first->id)->pluck('raw_event_id')->all())->toBe([$mappedOutId]);
    $sameInput = $service->calculate($company->id, $staff->id, '2026-06-01', $admin->id);
    expect($sameInput->id)->toBe($first->id)
        ->and((int) $sameInput->result_version)->toBe(1);

    $mappingId = (string) Str::uuid();
    DB::table('hr_attendance_person_mappings')->insert([
        'id' => $mappingId, 'company_id' => $company->id, 'staff_id' => $staff->id, 'device_id' => $device->id,
        'provider_person_id' => 'person-1', 'employee_number_snapshot' => $staff->code, 'enrollment_status' => 'verified',
        'effective_from' => '2026-01-01', 'created_by' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_attendance_quarantine_items')->where('id', $quarantineId)->update([
        'status' => 'resolved', 'resolved_staff_id' => $staff->id, 'resolved_mapping_id' => $mappingId,
        'resolved_by' => $admin->id, 'resolved_at' => $now, 'resolution_reason' => 'Verified mapping', 'updated_at' => $now,
    ]);

    $resolved = $service->calculate($company->id, $staff->id, '2026-06-01', $admin->id);
    $sourceIds = DB::table('hr_attendance_daily_result_sources')->where('daily_result_id', $resolved->id)->pluck('raw_event_id')->all();
    expect($resolved->worked_minutes)->toBe(540)
        ->and($resolved->day_status)->toBe('present')
        ->and($sourceIds)->toContain($mappedOutId, $quarantinedInId);

    $otherCalendarId = (string) Str::uuid();
    DB::table('hr_work_calendars')->insert([
        'id' => $otherCalendarId, 'company_id' => $otherCompany->id, 'code' => 'other', 'name' => 'Other tenant calendar', 'timezone' => 'Asia/Colombo',
        'weekly_working_days' => json_encode(['monday'], JSON_THROW_ON_ERROR), 'effective_from' => '2026-01-01', 'status' => 'active',
        'created_by' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_roster_assignments')->where('id', $rosterId)->update(['calendar_id' => $otherCalendarId]);
    expect(fn () => $service->calculate($company->id, $staff->id, '2026-06-01', $admin->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    DB::table('hr_roster_assignments')->where('id', $rosterId)->update(['calendar_id' => $calendarId]);
    DB::table('hr_attendance_daily_results')->where('id', $resolved->id)->update(['company_id' => $otherCompany->id]);
    expect(fn () => $service->calculate($company->id, $staff->id, '2026-06-01', $admin->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'The latest attendance result does not belong to the selected legal entity.');
});

it('refuses direct attendance result calculation when the company is inactive', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    config(['hr.features.attendance_results' => true]);
    DB::table('companies')->where('id', $company->id)->update(['is_active' => false]);

    expect(fn () => app(AttendanceResultService::class)->calculate($company->id, $staff->id, '2026-10-01', $admin->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'Attendance result writes require an active legal entity.');
    expect(DB::table('hr_attendance_daily_results')->where('company_id', $company->id)->exists())->toBeFalse();
});
