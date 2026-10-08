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
    ] as $model) {
        expect(is_subclass_of($model, NonSoftDeletableModel::class))->toBeTrue();
    }

    foreach ([
        App\Models\Hr\HrEmployeeNumberSequence::class,
        App\Models\Hr\HrReportingLine::class,
        App\Models\Hr\Attendance\AttendanceConnector::class,
        App\Models\Hr\Attendance\AttendanceDevice::class,
        App\Models\Hr\Attendance\AttendanceRawEvent::class,
        App\Models\Hr\Attendance\AttendanceDailyResult::class,
    ] as $model) {
        expect(is_subclass_of($model, NonSoftDeletableTrackedModel::class))->toBeTrue();
    }
    expect((new App\Models\Hr\Attendance\AttendanceConnector())->getGlobalScopes())->toHaveKey('not_deleted')
        ->and((new App\Models\Hr\Attendance\AttendanceDevice())->getGlobalScopes())->toHaveKey('not_deleted');

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
        ->and($attendanceCompatibilityMigration)->toContain('$table->softDeletes()')
        ->and($attendanceCompatibilityMigration)->toContain("whereNotNull('created_user_id')->orWhereNotNull('updated_user_id')->orWhereNotNull('deleted_at')")
        ->and($attendanceCompatibilityMigration)->toContain('Rollback refused: export and reconcile attendance result tracking and deletion evidence first.');
    $attendanceModel = file_get_contents(app_path('Models/Hr/Attendance/AttendanceDailyResult.php'));
    expect($attendanceModel)->toContain('extends NonSoftDeletableTrackedModel')
        ->and($attendanceModel)->toContain("protected \$hidden = ['created_user_id', 'updated_user_id', 'calculated_by']");
    foreach ([
        App\Models\Hr\Attendance\AttendanceConnector::class,
        App\Models\Hr\Attendance\AttendanceDevice::class,
        App\Models\Hr\Attendance\AttendanceRawEvent::class,
    ] as $attendanceModelClass) {
        expect((new $attendanceModelClass())->getHidden())->toContain('created_user_id', 'updated_user_id');
    }
    $attendanceModelMigration = file_get_contents(database_path('migrations/2026_08_31_171000_align_attendance_models_with_base_model.php'));
    expect($attendanceModelMigration)
        ->toContain("whereNotNull('updated_user_id')->orWhereNotNull('deleted_at')")
        ->toContain('Rollback refused: export and reconcile attendance tracking/deletion history for {$tableName} first.');
    $employmentModelMigration = file_get_contents(database_path('migrations/2026_08_31_173000_align_employment_models_with_base_model.php'));
    expect($employmentModelMigration)
        ->toContain("whereNotNull('updated_user_id')->orWhereNotNull('deleted_at')")
        ->toContain("whereNotNull('created_user_id')->orWhereNotNull('updated_user_id')->orWhereNotNull('deleted_at')")
        ->toContain('Rollback refused: export and reconcile employment spell tracking and deletion evidence first.')
        ->toContain('Rollback refused: export and reconcile employment assignment tracking and deletion evidence first.');
    expect((new App\Models\Hr\HrEmploymentSpell())->getHidden())->toContain('created_user_id', 'updated_user_id')
        ->and((new App\Models\Hr\HrEmploymentAssignment())->getHidden())->toContain('created_user_id', 'updated_user_id');
    $rehireApprovalMigration = file_get_contents(database_path('migrations/2026_10_07_000005_add_rehire_approval_payload_checksum.php'));
    expect($rehireApprovalMigration)
        ->toContain("char('approval_payload_checksum', 64)->nullable()")
        ->toContain("whereNotNull('approval_payload_checksum')->exists()")
        ->toContain('Rollback refused: export and reconcile rehire approval replay evidence first.');
    expect((new App\Models\Hr\HrRehireCase())->getHidden())->toContain('request_payload_checksum', 'approval_payload_checksum');
    $peopleCoreMigration = file_get_contents(database_path('migrations/2026_08_12_147000_create_hr_people_core.php'));
    expect($peopleCoreMigration)
        ->toContain('Schema::hasTable($table) && DB::table($table)->exists()')
        ->toContain('Refusing to drop {$table} while HR People Core records exist.');
    $rawEventTrackingMigration = file_get_contents(database_path('migrations/2026_09_01_140000_add_raw_event_creator_tracking.php'));
    expect($rawEventTrackingMigration)
        ->toContain("whereNotNull('created_user_id')->exists()")
        ->toContain('Rollback refused: export and reconcile raw attendance event creator evidence first.');

    expect(is_subclass_of(App\Models\Hr\HrPeopleIdentityLink::class, App\Models\BaseModel::class))->toBeTrue();
    $identitySoftDeleteMigration = file_get_contents(database_path('migrations/2026_09_04_122100_add_soft_deletes_to_hr_people_identity_links.php'));
    expect($identitySoftDeleteMigration)->toContain("Schema::table('hr_people_identity_links'")
        ->and($identitySoftDeleteMigration)->toContain('$table->softDeletes()')
        ->toContain("whereNotNull('deleted_at')->exists()")
        ->toContain('Rollback refused: export and reconcile identity-link deletion evidence first.');
    $identityTrackingMigration = file_get_contents(database_path('migrations/2026_09_04_122200_add_user_tracking_to_hr_people_identity_links.php'));
    expect($identityTrackingMigration)->toContain("Schema::table('hr_people_identity_links'")
        ->and($identityTrackingMigration)->toContain("foreignUuid('created_user_id')")
        ->and($identityTrackingMigration)->toContain("foreignUuid('updated_user_id')")
        ->toContain("whereNotNull('created_user_id')->orWhereNotNull('updated_user_id')")
        ->toContain('Rollback refused: export and reconcile identity-link actor evidence first.');

    $softDeleteModels = [
        App\Models\Hr\HrEmploymentSpell::class => ['hr_employment_spells', '2026_08_31_173000_align_employment_models_with_base_model.php'],
        App\Models\Hr\HrEmploymentAssignment::class => ['hr_employment_assignments', '2026_08_31_173000_align_employment_models_with_base_model.php'],
        App\Models\Hr\HrPeopleImportJob::class => ['hr_people_import_jobs', '2026_09_04_120000_create_hr_people_core_migration_evidence.php'],
        App\Models\Hr\HrPeopleExport::class => ['hr_people_exports', '2026_09_04_120000_create_hr_people_core_migration_evidence.php'],
        App\Models\Hr\HrPeopleDuplicateReview::class => ['hr_people_duplicate_reviews', '2026_09_04_121000_create_hr_people_duplicate_reviews.php'],
        App\Models\Hr\HrPeopleIdentityLink::class => ['hr_people_identity_links', '2026_09_04_122100_add_soft_deletes_to_hr_people_identity_links.php'],
        App\Models\Hr\HrEpfEtfContributionPolicy::class => ['hr_epf_etf_contribution_policies', '2026_08_24_100000_create_hr_payroll_statutory_policies.php'],
        App\Models\Hr\HrGratuityPolicy::class => ['hr_gratuity_policies', '2026_08_24_100000_create_hr_payroll_statutory_policies.php'],
    ];
    foreach ($softDeleteModels as $model => [$table, $migrationFile]) {
        $migration = file_get_contents(database_path("migrations/$migrationFile"));
        $start = strpos($migration, "Schema::create('$table'");
        if ($start === false) $start = strpos($migration, "Schema::table('$table'");
        expect($start)->not->toBeFalse();
        $end = strpos($migration, '});', $start);
        if ($end === false) $end = strpos($migration, "\n", $start) ?: strlen($migration);
        expect(is_subclass_of($model, App\Models\BaseModel::class))->toBeTrue()
            ->and($end)->not->toBeFalse()
            ->and(substr($migration, $start, $end - $start))->toContain('->softDeletes()');
    }

    $trackedBaseModels = [
        App\Models\Hr\HrEmploymentSpell::class => ['2026_08_12_147000_create_hr_people_core.php', '2026_08_31_173000_align_employment_models_with_base_model.php'],
        App\Models\Hr\HrEmploymentAssignment::class => ['2026_08_31_173000_align_employment_models_with_base_model.php'],
        App\Models\Hr\HrPeopleImportJob::class => ['2026_09_04_120000_create_hr_people_core_migration_evidence.php'],
        App\Models\Hr\HrPeopleExport::class => ['2026_09_04_120000_create_hr_people_core_migration_evidence.php'],
        App\Models\Hr\HrPeopleDuplicateReview::class => ['2026_09_04_121000_create_hr_people_duplicate_reviews.php'],
        App\Models\Hr\HrPeopleIdentityLink::class => ['2026_09_04_122200_add_user_tracking_to_hr_people_identity_links.php'],
    ];
    foreach ($trackedBaseModels as $model => $migrationFiles) {
        $schema = implode('', array_map(fn ($file) => file_get_contents(database_path("migrations/$file")), $migrationFiles));
        expect(is_subclass_of($model, App\Models\BaseModel::class))->toBeTrue()
            ->and($schema)->toContain("'created_user_id'", "'updated_user_id'");
    }

    $statutoryPolicyModels = [
        App\Models\Hr\HrEpfEtfContributionPolicy::class,
        App\Models\Hr\HrGratuityPolicy::class,
    ];
    $statutoryPolicySchema = file_get_contents(database_path('migrations/2026_08_24_100000_create_hr_payroll_statutory_policies.php'));
    foreach ($statutoryPolicyModels as $model) {
        expect((new ReflectionProperty($model, 'useUserTracking'))->getValue(new $model))->toBeFalse()
            ->and($statutoryPolicySchema)->toContain("'created_by'")
            ->not->toContain("'created_user_id'");
    }
});
