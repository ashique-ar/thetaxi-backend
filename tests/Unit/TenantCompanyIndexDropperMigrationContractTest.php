<?php

it('drops tenant indexes through the PostgreSQL constraint-aware helper', function () {
    $migrations = [
        '2026_10_30_000010_remove_sms_tenant_company_ids.php',
        '2026_10_30_000011_remove_booking_extension_tenant_company.php',
        '2026_10_30_000012_remove_booking_tenant_company.php',
        '2026_10_30_000013_remove_attendance_period_tenant_company.php',
        '2026_10_30_000016_remove_attendance_result_tenant_company.php',
        '2026_10_30_000017_remove_attendance_configuration_tenant_company.php',
        '2026_10_30_000020_remove_hr_report_tenant_company.php',
        '2026_10_30_000021_remove_hr_analytics_workforce_tenant_company.php',
        '2026_10_30_000022_remove_work_calendar_tenant_company.php',
        '2026_10_30_000023_remove_custom_field_tenant_company.php',
        '2026_10_30_000025_remove_hr_statutory_policy_tenant_company.php',
        '2026_10_30_000027_remove_medical_record_tenant_company.php',
    ];

    foreach ($migrations as $migrationFile) {
        $source = file_get_contents(database_path('migrations/'.$migrationFile));
        $upStart = strpos($source, 'public function up()');
        $downStart = strpos($source, 'public function down()', $upStart);
        $up = substr($source, $upStart, $downStart - $upStart);

        expect($up)
            ->toContain('TenantIndexDropper::drop(')
            ->not->toContain('->dropIndex(', '->dropUnique(');
    }
});
