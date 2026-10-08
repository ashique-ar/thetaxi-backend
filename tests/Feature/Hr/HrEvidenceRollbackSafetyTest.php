<?php

use App\Models\Company;
use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('refuses to drop populated attendance ledger evidence', function () {
    $company = Company::create(['name' => 'Attendance rollback company', 'is_active' => true]);
    $device = AttendanceDevice::factory()->create(['company_id' => $company->id]);
    $migration = require database_path('migrations/2026_08_13_100000_create_hr_attendance_raw_ledger.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile attendance evidence from hr_attendance_devices first.');
    expect(Schema::hasTable('hr_attendance_devices'))->toBeTrue()
        ->and(DB::table('hr_attendance_devices')->where('id', $device->id)->exists())->toBeTrue();
});

it('preflights every attendance model table before removing alignment columns', function () {
    $company = Company::create(['name' => 'Attendance alignment rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $device = AttendanceDevice::factory()->create(['company_id' => $company->id]);
    DB::table('hr_attendance_devices')->where('id', $device->id)->update(['updated_user_id' => $actor->id]);
    $migration = require database_path('migrations/2026_08_31_171000_align_attendance_models_with_base_model.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile attendance tracking/deletion history for hr_attendance_devices first.');
    expect(Schema::hasColumn('hr_attendance_raw_events', 'updated_user_id'))->toBeTrue()
        ->and(Schema::hasColumn('hr_attendance_raw_events', 'deleted_at'))->toBeTrue()
        ->and(DB::table('hr_attendance_devices')->where('id', $device->id)->value('updated_user_id'))->toBe($actor->id);
});

it('preflights both employment tables before removing alignment columns', function () {
    $company = Company::create(['name' => 'Employment alignment rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $staff = \App\Models\Staff::factory()->create(['company_id' => $company->id]);
    $spellId = (string) Str::uuid();
    $assignmentId = (string) Str::uuid();
    DB::table('hr_employment_spells')->insert([
        'id' => $spellId,
        'staff_id' => $staff->id,
        'company_id' => $company->id,
        'spell_number' => 1,
        'joined_at' => '2026-01-01',
        'service_date' => '2026-01-01',
        'gratuity_service_start' => '2026-01-01',
        'created_user_id' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('hr_employment_assignments')->insert([
        'id' => $assignmentId,
        'employment_spell_id' => $spellId,
        'staff_id' => $staff->id,
        'company_id' => $company->id,
        'assignment_type' => 'primary',
        'effective_from' => '2026-01-01',
        'change_reason' => 'Synthetic alignment fixture',
        'snapshot' => json_encode([], JSON_THROW_ON_ERROR),
        'created_user_id' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_31_173000_align_employment_models_with_base_model.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile employment assignment tracking and deletion evidence first.');
    expect(Schema::hasColumn('hr_employment_spells', 'updated_user_id'))->toBeTrue()
        ->and(Schema::hasColumn('hr_employment_spells', 'deleted_at'))->toBeTrue()
        ->and(DB::table('hr_employment_assignments')->where('id', $assignmentId)->value('created_user_id'))->toBe($actor->id);
});

it('refuses to drop populated attendance-results evidence', function () {
    $company = Company::create(['name' => 'Attendance results rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $calendarId = (string) Str::uuid();
    DB::table('hr_work_calendars')->insert([
        'id' => $calendarId,
        'company_id' => $company->id,
        'code' => 'ROLLBACK-CALENDAR',
        'name' => 'Retained calendar',
        'timezone' => 'Asia/Colombo',
        'weekly_working_days' => json_encode([1, 2, 3, 4, 5], JSON_THROW_ON_ERROR),
        'effective_from' => '2026-01-01',
        'status' => 'active',
        'created_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_13_101000_create_hr_attendance_results.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile attendance-results evidence from hr_work_calendars first.');
    expect(Schema::hasTable('hr_work_calendars'))->toBeTrue()
        ->and(DB::table('hr_work_calendars')->where('id', $calendarId)->exists())->toBeTrue();
});

it('refuses to drop populated daily-result tracking evidence', function () {
    $company = Company::create(['name' => 'Attendance tracking rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $staff = \App\Models\Staff::factory()->create(['company_id' => $company->id]);
    $resultId = (string) Str::uuid();
    DB::table('hr_attendance_daily_results')->insert([
        'id' => $resultId,
        'company_id' => $company->id,
        'staff_id' => $staff->id,
        'work_date' => '2026-10-01',
        'result_version' => 1,
        'day_status' => 'present',
        'worked_minutes' => 480,
        'late_minutes' => 0,
        'early_leave_minutes' => 0,
        'payable_minutes' => 480,
        'source_kind' => 'calculated',
        'calculated_at' => now(),
        'input_checksum' => str_repeat('a', 64),
        'result_checksum' => str_repeat('b', 64),
        'rule_snapshot' => json_encode([], JSON_THROW_ON_ERROR),
        'created_user_id' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_09_01_160000_align_attendance_daily_results_with_base_model.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile attendance result tracking and deletion evidence first.');
    expect(Schema::hasColumn('hr_attendance_daily_results', 'created_user_id'))->toBeTrue()
        ->and(DB::table('hr_attendance_daily_results')->where('id', $resultId)->value('created_user_id'))->toBe($actor->id);
});

it('refuses to drop populated lifecycle request evidence', function () {
    $company = Company::create(['name' => 'Lifecycle rollback company', 'is_active' => true]);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    $requestId = (string) Str::uuid();
    DB::table('hr_request_index')->insert([
        'id' => $requestId,
        'company_id' => $company->id,
        'requester_staff_id' => $staff->id,
        'request_type' => 'leave',
        'source_type' => 'leave_request',
        'source_id' => (string) Str::uuid(),
        'status' => 'submitted',
        'summary' => 'Retain request history',
        'capability_snapshot' => json_encode([], JSON_THROW_ON_ERROR),
        'submitted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_13_103000_create_hr_ess_recruitment_and_lifecycle.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile HR lifecycle/recruitment evidence from hr_request_index first.');
    expect(Schema::hasTable('hr_request_index'))->toBeTrue()
        ->and(DB::table('hr_request_index')->where('id', $requestId)->exists())->toBeTrue();
});

it('refuses to drop populated leave and payroll evidence', function () {
    $company = Company::create(['name' => 'Leave rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $leaveTypeId = (string) Str::uuid();
    DB::table('hr_leave_types')->insert([
        'id' => $leaveTypeId,
        'company_id' => $company->id,
        'code' => 'ROLLBACK-LEAVE',
        'name' => 'Retained leave type',
        'category' => 'annual',
        'unit' => 'days',
        'paid' => true,
        'medical_confidential' => false,
        'effective_from' => '2026-01-01',
        'status' => 'active',
        'created_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_13_102000_create_hr_leave_overtime_and_timesheets.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile leave/payroll evidence from hr_leave_types first.');
    expect(Schema::hasTable('hr_leave_types'))->toBeTrue()
        ->and(DB::table('hr_leave_types')->where('id', $leaveTypeId)->exists())->toBeTrue();
});

it('refuses to drop populated talent and performance evidence', function () {
    $company = Company::create(['name' => 'Performance rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $cycleId = (string) Str::uuid();
    DB::table('hr_review_cycles')->insert([
        'id' => $cycleId,
        'company_id' => $company->id,
        'code' => 'ROLLBACK-CYCLE',
        'name' => 'Retained review cycle',
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'stages' => json_encode([], JSON_THROW_ON_ERROR),
        'status' => 'draft',
        'created_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_13_104000_create_hr_talent_learning_and_operations.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile talent/performance evidence from hr_review_cycles first.');
    expect(Schema::hasTable('hr_knowledge_articles'))->toBeTrue()
        ->and(Schema::hasTable('hr_review_cycles'))->toBeTrue()
        ->and(DB::table('hr_review_cycles')->where('id', $cycleId)->exists())->toBeTrue();
});

it('refuses to drop populated talent audit evidence', function () {
    $company = Company::create(['name' => 'Talent audit rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    $achievementId = (string) Str::uuid();
    $eventId = (string) Str::uuid();
    DB::table('hr_achievements')->insert([
        'id' => $achievementId,
        'company_id' => $company->id,
        'staff_id' => $staff->id,
        'category' => 'delivery',
        'title' => 'Retained achievement',
        'description' => 'Evidence remains available.',
        'achievement_date' => '2026-10-01',
        'outcome_snapshot' => json_encode([], JSON_THROW_ON_ERROR),
        'visibility' => 'manager',
        'status' => 'pending_verification',
        'submitted_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('hr_achievement_events')->insert([
        'id' => $eventId,
        'achievement_id' => $achievementId,
        'event_type' => 'submitted',
        'to_status' => 'pending_verification',
        'actor_user_id' => $actor->id,
        'occurred_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_13_104100_add_hr_talent_audit_events.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile talent/performance audit evidence from hr_achievement_events first.');
    expect(Schema::hasTable('hr_achievement_events'))->toBeTrue()
        ->and(Schema::hasTable('hr_performance_review_events'))->toBeTrue()
        ->and(DB::table('hr_achievement_events')->where('id', $eventId)->exists())->toBeTrue();
});

it('refuses to drop populated expense decision evidence', function () {
    $company = Company::create(['name' => 'Expense rollback company', 'is_active' => true]);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    $claimId = (string) Str::uuid();
    DB::table('hr_expense_claims')->insert([
        'id' => $claimId,
        'company_id' => $company->id,
        'staff_id' => $staff->id,
        'claim_number' => 'ROLLBACK-CLAIM',
        'claim_type' => 'reimbursement',
        'currency' => 'LKR',
        'claimed_amount' => 100,
        'status' => 'rejected',
        'allocation_snapshot' => json_encode([], JSON_THROW_ON_ERROR),
        'content_checksum' => str_repeat('c', 64),
        'decision_reason' => 'Retain decision history',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_13_104200_add_hr_service_operations_controls.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile governed expense-claim policy and decision evidence first.');
    expect(Schema::hasColumn('hr_expense_claims', 'decision_reason'))->toBeTrue()
        ->and(DB::table('hr_expense_claims')->where('id', $claimId)->value('decision_reason'))->toBe('Retain decision history')
        ->and(Schema::hasTable('hr_expense_claim_events'))->toBeTrue();
});

it('refuses to drop populated custody extension evidence', function () {
    $company = Company::create(['name' => 'Custody rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    $assignmentId = (string) Str::uuid();
    DB::table('hr_custody_assignments')->insert([
        'id' => $assignmentId,
        'company_id' => $company->id,
        'staff_id' => $staff->id,
        'custody_type' => 'equipment',
        'item_code' => 'ROLLBACK-ITEM',
        'item_name' => 'Retained asset record',
        'assigned_at' => '2026-01-01',
        'assigned_by' => $actor->id,
        'status' => 'assigned',
        'issue_reason' => 'issued_for_role',
        'return_reason' => 'Retain return evidence',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_13_104300_create_hr_assets_travel_and_knowledge_workflows.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile custody asset, acknowledgement and return evidence first.');
    expect(Schema::hasTable('hr_knowledge_article_events'))->toBeTrue()
        ->and(Schema::hasColumn('hr_custody_assignments', 'return_reason'))->toBeTrue()
        ->and(DB::table('hr_custody_assignments')->where('id', $assignmentId)->value('return_reason'))->toBe('Retain return evidence');
});

it('refuses to drop populated talent and succession audit evidence', function () {
    $actor = User::factory()->create();
    $eventId = (string) Str::uuid();
    DB::table('hr_talent_events')->insert([
        'id' => $eventId,
        'aggregate_type' => 'development_plan',
        'aggregate_id' => (string) Str::uuid(),
        'event_type' => 'approved',
        'to_status' => 'approved',
        'actor_user_id' => $actor->id,
        'occurred_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_13_104400_create_hr_talent_and_succession_workflows.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile talent/succession evidence from hr_talent_events first.');
    expect(Schema::hasTable('hr_talent_events'))->toBeTrue()
        ->and(Schema::hasTable('hr_talent_recommendations'))->toBeTrue()
        ->and(Schema::hasColumn('hr_development_plans', 'content_checksum'))->toBeTrue()
        ->and(DB::table('hr_talent_events')->where('id', $eventId)->exists())->toBeTrue();
});

it('refuses to drop populated meal-programme evidence', function () {
    $actor = User::factory()->create();
    $eventId = (string) Str::uuid();
    DB::table('hr_meal_events')->insert([
        'id' => $eventId,
        'aggregate_type' => 'employee_order',
        'aggregate_id' => (string) Str::uuid(),
        'event_type' => 'generated',
        'to_status' => 'generated',
        'actor_user_id' => $actor->id,
        'occurred_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_13_104500_create_hr_meal_programme.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile HR meal-programme evidence from hr_meal_events first.');
    expect(Schema::hasTable('hr_meal_events'))->toBeTrue()
        ->and(Schema::hasTable('hr_meal_dietary_profiles'))->toBeTrue()
        ->and(DB::table('hr_meal_events')->where('id', $eventId)->exists())->toBeTrue();
});

it('refuses to drop accounting delivery target evidence', function () {
    $company = Company::create(['name' => 'Meal delivery rollback company', 'is_active' => true]);
    $outboxId = (string) Str::uuid();
    DB::table('hr_accounting_outbox')->insert([
        'id' => $outboxId,
        'company_id' => $company->id,
        'event_type' => 'meal_order',
        'aggregate_type' => 'meal_order',
        'aggregate_id' => (string) Str::uuid(),
        'target_system' => 'accounting',
        'payload' => json_encode([], JSON_THROW_ON_ERROR),
        'payload_checksum' => str_repeat('d', 64),
        'status' => 'pending',
        'attempts' => 0,
        'available_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_13_104600_create_hr_meal_variance_and_integration_delivery.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile accounting-delivery target evidence first.');
    expect(Schema::hasTable('hr_integration_deliveries'))->toBeTrue()
        ->and(Schema::hasColumn('hr_accounting_outbox', 'target_system'))->toBeTrue()
        ->and(DB::table('hr_accounting_outbox')->where('id', $outboxId)->value('target_system'))->toBe('accounting');
});

it('refuses to drop populated relations and safety case evidence', function () {
    $company = Company::create(['name' => 'Relations rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $caseId = (string) Str::uuid();
    DB::table('hr_relation_cases')->insert([
        'id' => $caseId,
        'company_id' => $company->id,
        'case_number' => 'ROLLBACK-CASE',
        'case_type' => 'grievance',
        'severity' => 'normal',
        'confidentiality' => 'restricted',
        'subject' => 'Retained case fixture',
        'encrypted_summary' => encrypt('Synthetic test evidence'),
        'status' => 'open',
        'opened_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_13_105000_create_hr_relations_and_safety.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile HR relations/safety evidence from hr_relation_cases first.');
    expect(Schema::hasTable('hr_fitness_restrictions'))->toBeTrue()
        ->and(Schema::hasTable('hr_relation_cases'))->toBeTrue()
        ->and(DB::table('hr_relation_cases')->where('id', $caseId)->exists())->toBeTrue();
});

it('refuses to drop populated safety register audit evidence', function () {
    $company = Company::create(['name' => 'Safety audit rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $eventId = (string) Str::uuid();
    DB::table('hr_safety_register_events')->insert([
        'id' => $eventId,
        'company_id' => $company->id,
        'register_type' => 'hazard',
        'register_id' => (string) Str::uuid(),
        'event_type' => 'created',
        'event_checksum' => str_repeat('e', 64),
        'actor_user_id' => $actor->id,
        'occurred_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_13_105100_extend_hr_relations_and_safety_operations.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile HR safety/relations evidence from hr_safety_register_events first.');
    expect(Schema::hasTable('hr_safety_register_events'))->toBeTrue()
        ->and(Schema::hasTable('hr_safety_external_notifications'))->toBeTrue()
        ->and(DB::table('hr_safety_register_events')->where('id', $eventId)->exists())->toBeTrue();
});

it('refuses to drop populated engagement and wellness evidence', function () {
    $company = Company::create(['name' => 'Engagement rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $announcementId = (string) Str::uuid();
    DB::table('hr_announcements')->insert([
        'id' => $announcementId,
        'company_id' => $company->id,
        'title' => 'Retained announcement fixture',
        'body' => 'Synthetic test content.',
        'audience' => json_encode([], JSON_THROW_ON_ERROR),
        'priority' => 'normal',
        'acknowledgement_required' => false,
        'publish_at' => now(),
        'source_timezone' => 'Asia/Colombo',
        'status' => 'draft',
        'created_by' => $actor->id,
        'content_checksum' => str_repeat('f', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_13_105200_create_hr_engagement_wellness_and_analytics.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile HR engagement/wellness/analytics evidence from hr_announcements first.');
    expect(Schema::hasTable('hr_analytics_snapshots'))->toBeTrue()
        ->and(Schema::hasTable('hr_announcements'))->toBeTrue()
        ->and(DB::table('hr_announcements')->where('id', $announcementId)->exists())->toBeTrue();
});

it('refuses to drop populated workforce-planning evidence', function () {
    $company = Company::create(['name' => 'Workforce planning rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $planId = (string) Str::uuid();
    DB::table('hr_workforce_plan_versions')->insert([
        'id' => $planId,
        'company_id' => $company->id,
        'code' => 'ROLLBACK-PLAN',
        'version' => 1,
        'name' => 'Retained plan fixture',
        'baseline_as_of' => '2026-10-01',
        'horizon_start' => '2027-01-01',
        'horizon_end' => '2027-12-31',
        'assumptions' => json_encode([], JSON_THROW_ON_ERROR),
        'status' => 'draft',
        'created_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_13_105300_create_hr_workforce_planning.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile workforce-planning evidence from hr_workforce_plan_versions first.');
    expect(Schema::hasTable('hr_workforce_plan_events'))->toBeTrue()
        ->and(Schema::hasTable('hr_workforce_plan_versions'))->toBeTrue()
        ->and(DB::table('hr_workforce_plan_versions')->where('id', $planId)->exists())->toBeTrue();
});

it('refuses to drop populated reporting and data-quality evidence', function () {
    $company = Company::create(['name' => 'Reporting rollback company', 'is_active' => true]);
    $owner = User::factory()->create();
    $viewId = (string) Str::uuid();
    DB::table('hr_report_saved_views')->insert([
        'id' => $viewId,
        'company_id' => $company->id,
        'owner_user_id' => $owner->id,
        'name' => 'Retained view fixture',
        'report_kind' => 'workforce',
        'filter_contract' => json_encode([], JSON_THROW_ON_ERROR),
        'column_contract' => json_encode([], JSON_THROW_ON_ERROR),
        'visibility' => 'private',
        'status' => 'active',
        'view_checksum' => str_repeat('a', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_13_105400_create_hr_reporting_and_data_quality.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile HR reporting/data-quality evidence from hr_report_saved_views first.');
    expect(Schema::hasTable('hr_report_delivery_events'))->toBeTrue()
        ->and(Schema::hasTable('hr_report_saved_views'))->toBeTrue()
        ->and(DB::table('hr_report_saved_views')->where('id', $viewId)->exists())->toBeTrue();
});

it('refuses to drop populated notification template evidence', function () {
    $company = Company::create(['name' => 'Notification rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $templateId = (string) Str::uuid();
    DB::table('hr_notification_template_versions')->insert([
        'id' => $templateId,
        'company_id' => $company->id,
        'code' => 'ROLLBACK-TEMPLATE',
        'version' => 1,
        'event_type' => 'leave_update',
        'channel' => 'in_app',
        'body_template' => 'Synthetic notification fixture',
        'allowed_placeholders' => json_encode([], JSON_THROW_ON_ERROR),
        'mandatory' => false,
        'effective_from' => '2026-01-01',
        'status' => 'active',
        'template_checksum' => str_repeat('b', 64),
        'created_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_13_105500_create_hr_notification_governance.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Rollback refused: export and reconcile HR notification evidence from hr_notification_template_versions first.');
    expect(Schema::hasTable('hr_notification_delivery_events'))->toBeTrue()
        ->and(Schema::hasTable('hr_notification_template_versions'))->toBeTrue()
        ->and(DB::table('hr_notification_template_versions')->where('id', $templateId)->exists())->toBeTrue();
});

it('refuses to drop evolved custom-field version evidence', function () {
    $company = Company::create(['name' => 'Custom-field rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $definitionId = (string) Str::uuid();
    $valueId = (string) Str::uuid();
    DB::table('hr_custom_field_definitions')->insert([
        'id' => $definitionId,
        'company_id' => $company->id,
        'applies_to' => 'staff',
        'field_key' => 'rollback_fixture',
        'label' => 'Synthetic fixture',
        'data_type' => 'text',
        'confidentiality' => 'internal',
        'required' => false,
        'active' => true,
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('hr_custom_field_values')->insert([
        'id' => $valueId,
        'definition_id' => $definitionId,
        'owner_type' => 'Staff',
        'owner_id' => (string) Str::uuid(),
        'encrypted_value' => 'synthetic fixture ciphertext',
        'updated_by' => $actor->id,
        'version' => 2,
        'definition_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_14_107000_govern_hr_organization_administration.php');

    expect(fn () => $migration->down())
        ->toThrow(LogicException::class, 'Refusing to remove evolved custom-field value version evidence.');
    DB::table('hr_custom_field_definitions')->where('id', $definitionId)->update(['updated_user_id' => $actor->id]);
    expect(fn () => $migration->down())
        ->toThrow(LogicException::class, 'Refusing to remove custom-field definition version or update-actor evidence.');
    expect(Schema::hasColumn('hr_custom_field_values', 'definition_version'))->toBeTrue()
        ->and(DB::table('hr_custom_field_values')->where('id', $valueId)->value('version'))->toBe(2);
});

it('refuses to drop evolved job-definition metadata', function () {
    $company = Company::create(['name' => 'Job definition rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $familyId = (string) Str::uuid();
    DB::table('hr_job_families')->insert([
        'id' => $familyId,
        'company_id' => $company->id,
        'code' => 'ROLLBACK-FAMILY',
        'name' => 'Retained family fixture',
        'status' => 'active',
        'version' => 2,
        'effective_from' => '2026-01-01',
        'updated_user_id' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_14_108000_govern_hr_job_and_position_administration.php');

    expect(fn () => $migration->down())
        ->toThrow(LogicException::class, 'Refusing to remove evolved HR job-definition evidence from hr_job_families.');
    expect(Schema::hasColumn('hr_job_families', 'effective_from'))->toBeTrue()
        ->and(DB::table('hr_job_families')->where('id', $familyId)->value('version'))->toBe(2);
});

it('refuses to drop evolved reporting-line governance evidence', function () {
    $company = Company::create(['name' => 'Reporting-line rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $manager = \App\Models\Staff::factory()->create(['company_id' => $company->id]);
    $member = \App\Models\Staff::factory()->create(['company_id' => $company->id]);
    $lineId = (string) Str::uuid();
    DB::table('hr_reporting_lines')->insert([
        'id' => $lineId,
        'company_id' => $company->id,
        'manager_staff_id' => $manager->id,
        'member_staff_id' => $member->id,
        'line_type' => 'primary',
        'effective_from' => '2026-01-01',
        'created_user_id' => $actor->id,
        'status' => 'active',
        'version' => 2,
        'reason' => 'Retained governance fixture',
        'idempotency_key' => 'rollback-reporting-line',
        'updated_user_id' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_14_109000_govern_hr_reporting_lines.php');

    expect(fn () => $migration->down())
        ->toThrow(LogicException::class, 'Refusing to remove evolved HR reporting-line status, version, reason, idempotency or update-actor evidence.');
    expect(Schema::hasTable('hr_reporting_line_events'))->toBeTrue()
        ->and(Schema::hasColumn('hr_reporting_lines', 'idempotency_key'))->toBeTrue()
        ->and(DB::table('hr_reporting_lines')->where('id', $lineId)->value('version'))->toBe(2);
});

it('refuses to drop populated custom-field value history metadata', function () {
    $company = Company::create(['name' => 'Custom-field history rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $definitionId = (string) Str::uuid();
    $valueId = (string) Str::uuid();
    DB::table('hr_custom_field_definitions')->insert([
        'id' => $definitionId,
        'company_id' => $company->id,
        'applies_to' => 'staff',
        'field_key' => 'history_fixture',
        'label' => 'Synthetic history fixture',
        'data_type' => 'text',
        'confidentiality' => 'internal',
        'required' => false,
        'active' => true,
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('hr_custom_field_values')->insert([
        'id' => $valueId,
        'definition_id' => $definitionId,
        'owner_type' => 'Staff',
        'owner_id' => (string) Str::uuid(),
        'encrypted_value' => encrypt('Synthetic fixture value'),
        'updated_by' => $actor->id,
        'version' => 1,
        'definition_version' => 1,
        'value_checksum' => str_repeat('a', 64),
        'effective_from' => '2026-01-01',
        'change_reason' => 'Retain history fixture',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_14_111000_govern_hr_staff_custom_field_values.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Custom-field values contain checksum, effective-date or change-reason evidence; export and reconcile it before rollback.');
    expect(Schema::hasColumn('hr_custom_field_values', 'value_checksum'))->toBeTrue()
        ->and(DB::table('hr_custom_field_values')->where('id', $valueId)->value('change_reason'))->toBe('Retain history fixture')
        ->and(Schema::hasTable('hr_custom_field_value_events'))->toBeTrue();
});

it('refuses to drop populated payroll-group definitions', function () {
    $company = Company::create(['name' => 'Payroll-group rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $groupId = (string) Str::uuid();
    DB::table('hr_payroll_groups')->insert([
        'id' => $groupId,
        'company_id' => $company->id,
        'code' => 'ROLLBACK-GROUP',
        'name' => 'Retained payroll group fixture',
        'pay_frequency' => 'monthly',
        'status' => 'active',
        'effective_from' => '2026-01-01',
        'version' => 1,
        'created_user_id' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_15_100000_create_hr_payroll_groups.php');

    expect(fn () => $migration->down())
        ->toThrow(LogicException::class, 'Refusing to remove populated HR payroll-group definitions. Export and reconcile the governed configuration first.');
    expect(Schema::hasTable('hr_payroll_groups'))->toBeTrue()
        ->and(DB::table('hr_payroll_groups')->where('id', $groupId)->exists())->toBeTrue();
});

it('refuses to drop retained statutory contribution policy evidence', function () {
    $company = Company::create(['name' => 'Contribution policy rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $policyId = (string) Str::uuid();
    DB::table('hr_epf_etf_contribution_policies')->insert([
        'id' => $policyId,
        'company_id' => $company->id,
        'version' => 1,
        'status' => 'retired',
        'employee_epf_rate_percent' => 1,
        'employer_epf_rate_percent' => 1,
        'employer_etf_rate_percent' => 1,
        'earnings_basis' => json_encode([], JSON_THROW_ON_ERROR),
        'statutory_reference' => 'Synthetic test fixture',
        'effective_from' => '2026-01-01',
        'reason' => 'Synthetic regression fixture',
        'created_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_24_100000_create_hr_payroll_statutory_policies.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Refusing to drop governed EPF/ETF statutory-policy evidence while rows exist.');
    expect(Schema::hasTable('hr_epf_etf_contribution_policies'))->toBeTrue()
        ->and(DB::table('hr_epf_etf_contribution_policies')->where('id', $policyId)->whereNotNull('deleted_at')->exists())->toBeTrue()
        ->and(Schema::hasTable('hr_gratuity_policies'))->toBeTrue();
});

it('refuses to drop retained gratuity policy evidence', function () {
    $company = Company::create(['name' => 'Gratuity policy rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $policyId = (string) Str::uuid();
    DB::table('hr_gratuity_policies')->insert([
        'id' => $policyId,
        'company_id' => $company->id,
        'version' => 1,
        'status' => 'draft',
        'minimum_qualifying_service_years' => 1,
        'minimum_employer_headcount_threshold' => 1,
        'monthly_paid_divisor' => 1,
        'non_monthly_daily_wage_multiplier' => 1,
        'non_monthly_lookback_months' => 1,
        'payment_deadline_days' => 1,
        'statutory_reference' => 'Synthetic test fixture',
        'effective_from' => '2026-01-01',
        'reason' => 'Synthetic regression fixture',
        'created_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_24_100000_create_hr_payroll_statutory_policies.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Refusing to drop governed gratuity statutory-policy evidence while rows exist.');
    expect(Schema::hasTable('hr_gratuity_policies'))->toBeTrue()
        ->and(DB::table('hr_gratuity_policies')->where('id', $policyId)->exists())->toBeTrue()
        ->and(Schema::hasTable('hr_epf_etf_contribution_policies'))->toBeTrue();
});

it('refuses to drop populated learning-requirement definitions', function () {
    $company = Company::create(['name' => 'Learning requirement rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $courseId = (string) Str::uuid();
    $requirementId = (string) Str::uuid();
    DB::table('hr_courses')->insert([
        'id' => $courseId,
        'company_id' => $company->id,
        'code' => 'ROLLBACK-COURSE',
        'title' => 'Synthetic course fixture',
        'status' => 'active',
        'certification' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('hr_learning_requirements')->insert([
        'id' => $requirementId,
        'company_id' => $company->id,
        'course_id' => $courseId,
        'due_days' => 30,
        'status' => 'active',
        'effective_from' => '2026-01-01',
        'created_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_15_103000_create_hr_learning_requirements.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Refusing to drop populated HR learning-requirement definitions.');
    expect(Schema::hasTable('hr_learning_requirements'))->toBeTrue()
        ->and(DB::table('hr_learning_requirements')->where('id', $requirementId)->exists())->toBeTrue();
});

it('refuses to drop retained document employment-spell links', function () {
    $company = Company::create(['name' => 'Document rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $staff = \App\Models\Staff::factory()->create(['company_id' => $company->id]);
    $spellId = (string) Str::uuid();
    $documentId = (string) Str::uuid();
    DB::table('hr_employment_spells')->insert([
        'id' => $spellId,
        'staff_id' => $staff->id,
        'company_id' => $company->id,
        'spell_number' => 1,
        'joined_at' => '2026-01-01',
        'service_date' => '2026-01-01',
        'gratuity_service_start' => '2026-01-01',
        'created_user_id' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('documents')->insert([
        'id' => $documentId,
        'documentable_type' => 'App\\Models\\Staff',
        'documentable_id' => $staff->id,
        'document_type' => 'identity',
        'document_number' => 'SYNTHETIC-DOCUMENT',
        'path' => 'test/fixture.pdf',
        'file_name' => 'fixture.pdf',
        'employment_spell_id' => $spellId,
        'created_user_id' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_15_101000_govern_hr_document_types_and_legal_hold.php');

    expect(fn () => $migration->down())
        ->toThrow(LogicException::class, 'Refusing to remove retained employment-spell document links.');
    expect(Schema::hasTable('hr_document_types'))->toBeTrue()
        ->and(Schema::hasColumn('documents', 'employment_spell_id'))->toBeTrue()
        ->and(DB::table('documents')->where('id', $documentId)->value('employment_spell_id'))->toBe($spellId);
});

it('refuses to drop leave return and recall confirmation evidence', function () {
    $company = Company::create(['name' => 'Leave return rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $staff = \App\Models\Staff::factory()->create(['company_id' => $company->id]);
    $leaveTypeId = (string) Str::uuid();
    $policyId = (string) Str::uuid();
    $requestId = (string) Str::uuid();
    DB::table('hr_leave_types')->insert([
        'id' => $leaveTypeId,
        'company_id' => $company->id,
        'code' => 'ROLLBACK-RETURN',
        'name' => 'Return fixture',
        'category' => 'annual',
        'unit' => 'days',
        'paid' => true,
        'medical_confidential' => false,
        'effective_from' => '2026-01-01',
        'status' => 'active',
        'created_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('hr_leave_policies')->insert([
        'id' => $policyId,
        'company_id' => $company->id,
        'leave_type_id' => $leaveTypeId,
        'code' => 'ROLLBACK-RETURN-POLICY',
        'version' => 1,
        'rules' => json_encode([], JSON_THROW_ON_ERROR),
        'effective_from' => '2026-01-01',
        'status' => 'active',
        'created_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('hr_leave_requests')->insert([
        'id' => $requestId,
        'company_id' => $company->id,
        'staff_id' => $staff->id,
        'leave_type_id' => $leaveTypeId,
        'policy_id' => $policyId,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-01',
        'unit' => 'days',
        'requested_minutes' => 480,
        'reserved_minutes' => 480,
        'status' => 'approved',
        'reason' => 'Synthetic return fixture',
        'calculation_snapshot' => json_encode([], JSON_THROW_ON_ERROR),
        'request_checksum' => str_repeat('d', 64),
        'idempotency_key' => 'rollback-return-confirmation',
        'requested_by' => $actor->id,
        'return_confirmed_by' => $actor->id,
        'return_confirmed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_15_102000_add_hr_leave_return_to_work.php');

    expect(fn () => $migration->down())
        ->toThrow(LogicException::class, 'Refusing to drop retained return-to-work confirmations.');
    expect(Schema::hasColumn('hr_leave_requests', 'return_confirmed_by'))->toBeTrue()
        ->and(DB::table('hr_leave_requests')->where('id', $requestId)->value('return_confirmed_by'))->toBe($actor->id);

    DB::table('hr_leave_requests')->where('id', $requestId)->update([
        'recalled_by' => $actor->id,
        'recalled_at' => now(),
    ]);
    $recallMigration = require database_path('migrations/2026_08_18_100000_add_hr_leave_recall.php');
    expect(fn () => $recallMigration->down())
        ->toThrow(LogicException::class, 'Refusing to drop retained leave recalls.');
    expect(Schema::hasColumn('hr_leave_requests', 'recalled_by'))->toBeTrue()
        ->and(DB::table('hr_leave_requests')->where('id', $requestId)->value('recalled_by'))->toBe($actor->id);
});

it('refuses to drop delegation checksums when the retry key is empty', function () {
    $company = Company::create(['name' => 'Delegation rollback company', 'is_active' => true]);
    $actor = User::factory()->create();
    $delegator = Staff::factory()->create(['company_id' => $company->id]);
    $delegate = Staff::factory()->create(['company_id' => $company->id]);
    $id = (string) Str::uuid();
    $checksum = str_repeat('e', 64);
    DB::table('hr_approval_delegations')->insert([
        'id' => $id,
        'company_id' => $company->id,
        'delegator_staff_id' => $delegator->id,
        'delegate_staff_id' => $delegate->id,
        'request_types' => json_encode(['leave'], JSON_THROW_ON_ERROR),
        'effective_from' => '2026-10-01',
        'effective_until' => '2026-10-31',
        'reason' => 'Retain replay checksum',
        'status' => 'pending_approval',
        'created_by' => $actor->id,
        'idempotency_key' => null,
        'request_payload_checksum' => $checksum,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_10_03_123000_add_idempotency_to_hr_approval_delegations.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot roll back delegation idempotency while requests reference its keys.');
    expect(Schema::hasColumn('hr_approval_delegations', 'request_payload_checksum'))->toBeTrue()
        ->and(DB::table('hr_approval_delegations')->where('id', $id)->value('request_payload_checksum'))->toBe($checksum);
});
