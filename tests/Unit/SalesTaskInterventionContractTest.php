<?php

it('freezes explicit UTC task deadlines without guessing legacy timezone provenance', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesCrmController.php'));
    $service = file_get_contents(app_path('Services/Sales/SalesCrmService.php'));
    $command = file_get_contents(app_path('Console/Commands/ProcessSalesTasks.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_14_103000_govern_sales_task_deadlines.php'));

    expect($controller)
        ->toContain('(Z|[+-]\\d{2}:\\d{2})')
        ->and($service)
        ->toContain("'contract_version' => 'explicit_utc_v1'")
        ->toContain("'deadline_checksum' => hash('sha256', CanonicalJson::encode(\$deadline))")
        ->toContain("featureEnabled(\$companyId, 'crm')")
        ->and($command)
        ->toContain("where('feature_key', 'crm')->where('status', 'approved')->where('enabled', true)")
        ->and($migration)
        ->toContain("'deadline_contract_version'")
        ->toContain('Rollback refused: export and reconcile governed Sales task deadline evidence first.');
});

it('derives overdue and repeated-miss facts from owner and status event history at the factual instant', function () {
    $facts = file_get_contents(app_path('Services/Sales/SalesTaskInterventionFactService.php'));
    $performance = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));
    $policy = file_get_contents(app_path('Services/Sales/SalesAlertPolicyContract.php'));

    expect($facts)
        ->toContain("stateAt(\$history, \$asOf)")
        ->toContain("stateAt(\$history, \$dueAt)")
        ->toContain("'task_deadline_or_history_missing_count'")
        ->toContain("'task_intervention_evidence_checksum'")
        ->and($performance)
        ->toContain("\$checks['overdue_tasks']")
        ->toContain("\$checks['repeatedly_missed_next_actions']")
        ->toContain("'governed_task_deadline_or_history_missing'")
        ->and($policy)
        ->toContain("'open_or_in_progress_at_period_end'")
        ->toContain("'owner_at_due'")
        ->toContain("'governed_sales_task_event_history'");
});

it('stores only privacy-minimized task checksums in the KPI row and never task titles or customer details', function () {
    $facts = file_get_contents(app_path('Services/Sales/SalesTaskInterventionFactService.php'));

    expect($facts)
        ->not->toContain("'title'")
        ->not->toContain("'customer_id'")
        ->toContain("'deadline_checksum'")
        ->toContain("CanonicalJson::encode(\$snapshot)");
});
