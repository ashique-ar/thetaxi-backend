<?php

it('requires a complete approved closed-month alert contract without inferred thresholds', function () {
    $contract = file_get_contents(app_path('Services/Sales/SalesAlertPolicyContract.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPerformanceController.php'));
    $performance = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));

    expect($contract)
        ->toContain("'closed_calendar_month'")
        ->toContain("'once_after_close'")
        ->toContain("'completed_calendar_months_only'")
        ->toContain("'prior_period_booking_commission'")
        ->toContain("'suppress_rule_and_flag'")
        ->toContain("'owner_user_id'")
        ->toContain('another cadence cannot be inferred')
        ->and($controller)
        ->toContain("'rules.evaluation.schedule.local_time' => ['required', 'date_format:H:i']")
        ->toContain("'rules.evaluation.owner_user_id' => ['required', 'uuid', 'exists:users,id']")
        ->and($performance)->toContain('active internal Staff user in the selected legal entity');
});

it('evaluates the policy frozen by period close on its due schedule exactly once', function () {
    $performance = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));
    $command = file_get_contents(app_path('Console/Commands/ProcessSalesPerformanceAlerts.php'));
    $schedule = file_get_contents(base_path('routes/console.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_14_101000_govern_sales_performance_alert_evaluations.php'));

    expect($performance)
        ->toContain("data_get(\$snapshot->source_reconciliation_snapshot, 'alert_policy_version_id')")
        ->toContain("whereIn('status', ['approved', 'superseded'])")
        ->toContain("'sales_alert_evaluation_runs'")
        ->toContain("'scheduled_for' => \$dueAt")
        ->toContain("'result_checksum'")
        ->and($command)
        ->toContain("{--commit}")
        ->toContain('Preview only; rerun with --commit')
        ->and($schedule)
        ->toContain("config('sales.features.performance_alert_evaluations')")
        ->toContain("sales:process-performance-alerts --commit")
        ->and($migration)
        ->toContain('sales_alert_evaluation_snapshot_policy_unique')
        ->toContain('Rollback refused: export and reconcile immutable Sales alert evaluation evidence first.');
});

it('keeps cohort and commission category distinct in frozen reliance evidence', function () {
    $facts = file_get_contents(app_path('Services/Sales/SalesMetricFactService.php'));
    $performance = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));

    expect($facts)
        ->toContain("'collection_cohort' => \$decision->collection_cohort")
        ->toContain("'commission_category' => \$decision->commission_category")
        ->and($performance)
        ->toContain("'prior_booking_commission_ratio_percent'")
        ->toContain("'prior_booking_collection_ratio_percent'")
        ->toContain("'long_term_commission_share_percent'")
        ->toContain("'commission_dimension_missing_count'")
        ->toContain('pre-governance alert requires reviewed disposition');
});
