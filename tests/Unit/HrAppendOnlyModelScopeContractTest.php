<?php

use App\Models\NonSoftDeletableModel;
use App\Models\NonSoftDeletableTrackedModel;

it('maps HR ledger, People Core and attendance models to their schema delete and tracking behavior', function () {
    foreach ([
        App\Models\Hr\Leave\LeaveBalanceEntry::class,
        App\Models\Hr\PayrollInputFact::class,
        App\Models\Hr\HrEmployeeNumberAlias::class,
        App\Models\Hr\HrEmployeeRecord::class,
        App\Models\Hr\HrEmployeeTimelineEvent::class,
        App\Models\Hr\HrRehireCase::class,
        App\Models\Hr\HrStaffProfileVersion::class,
        App\Models\Hr\Attendance\AttendanceDailyResult::class,
    ] as $model) {
        expect(is_subclass_of($model, NonSoftDeletableModel::class))->toBeTrue();
    }

    foreach ([
        App\Models\Hr\HrEmployeeNumberSequence::class,
        App\Models\Hr\HrReportingLine::class,
        App\Models\Hr\Attendance\AttendanceConnector::class,
        App\Models\Hr\Attendance\AttendanceDevice::class,
        App\Models\Hr\Attendance\AttendanceRawEvent::class,
    ] as $model) {
        expect(is_subclass_of($model, NonSoftDeletableTrackedModel::class))->toBeTrue();
    }

    $trackingBase = file_get_contents(app_path('Models/NonSoftDeletableTrackedModel.php'));
    expect($trackingBase)->toContain('Schema::hasColumn($model->getTable(), \'created_user_id\')', 'Schema::hasColumn($model->getTable(), \'updated_user_id\')', 'bootSoftDeletes');

    $schemaFiles = [
        'hr_employee_number_aliases' => '2026_08_12_147000_create_hr_people_core.php',
        'hr_employee_number_sequences' => '2026_08_12_147000_create_hr_people_core.php',
        'hr_employee_records' => '2026_08_12_147000_create_hr_people_core.php',
        'hr_employee_timeline_events' => '2026_08_12_147000_create_hr_people_core.php',
        'hr_rehire_cases' => '2026_08_12_147000_create_hr_people_core.php',
        'hr_reporting_lines' => '2026_08_12_147000_create_hr_people_core.php',
        'hr_staff_profile_versions' => '2026_08_12_147000_create_hr_people_core.php',
        'hr_leave_balance_entries' => '2026_08_13_102000_create_hr_leave_overtime_and_timesheets.php',
        'hr_payroll_input_facts' => '2026_08_13_102000_create_hr_leave_overtime_and_timesheets.php',
        'hr_attendance_daily_results' => '2026_08_13_101000_create_hr_attendance_results.php',
        'hr_attendance_connectors' => '2026_08_13_100000_create_hr_attendance_raw_ledger.php',
        'hr_attendance_devices' => '2026_08_13_100000_create_hr_attendance_raw_ledger.php',
        'hr_attendance_raw_events' => '2026_08_13_100000_create_hr_attendance_raw_ledger.php',
    ];

    foreach ($schemaFiles as $table => $migrationFile) {
        $migration = file_get_contents(database_path("migrations/$migrationFile"));
        $start = strpos($migration, "Schema::create('$table'");
        expect($start)->not->toBeFalse();
        $end = strpos($migration, '});', $start);
        expect($end)->not->toBeFalse();
        $definition = substr($migration, $start, $end - $start);
        expect($definition)->not->toContain('$table->softDeletes()');
    }

    $attendanceCompatibilityMigration = file_get_contents(database_path('migrations/2026_09_01_160000_align_attendance_daily_results_with_base_model.php'));
    expect($attendanceCompatibilityMigration)->toContain("Schema::table('hr_attendance_daily_results'")
        ->and($attendanceCompatibilityMigration)->toContain('$table->softDeletes()');

    expect(is_subclass_of(App\Models\Hr\HrPeopleIdentityLink::class, App\Models\BaseModel::class))->toBeTrue();
    $identitySoftDeleteMigration = file_get_contents(database_path('migrations/2026_09_04_122100_add_soft_deletes_to_hr_people_identity_links.php'));
    expect($identitySoftDeleteMigration)->toContain("Schema::table('hr_people_identity_links'")
        ->and($identitySoftDeleteMigration)->toContain('$table->softDeletes()');
    $identityTrackingMigration = file_get_contents(database_path('migrations/2026_09_04_122200_add_user_tracking_to_hr_people_identity_links.php'));
    expect($identityTrackingMigration)->toContain("Schema::table('hr_people_identity_links'")
        ->and($identityTrackingMigration)->toContain("foreignUuid('created_user_id')")
        ->and($identityTrackingMigration)->toContain("foreignUuid('updated_user_id')");
});
