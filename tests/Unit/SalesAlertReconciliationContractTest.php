<?php

it('reconciles an authorized alert through frozen row policy evaluation action and outbox evidence', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesAlertReconciliationService.php'));

    expect($service)
        ->toContain("'current_source_to_frozen_row'")
        ->toContain("'alert_evaluation_checksum'")
        ->toContain("'evaluation_run_outbox_link'")
        ->toContain("'snapshot_outbox_link'")
        ->toContain('action_chain_version_')
        ->toContain('action_outbox_link_')
        ->toContain("'company_reconciliation' => \$companyEvidence");
});

it('scopes the direct alert id before deciding whether company reconciliation can be returned', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPerformanceController.php'));
    $route = file_get_contents(base_path('routes/api.php'));

    expect($controller)
        ->toContain("\$this->scope->assertProfile(\$request->user(), \$profile, 'sales.performance.view-all', 'sales.performance.view-team')")
        ->toContain("\$reconciliation->reconcile(\$alert, \$ids === null)")
        ->and($route)
        ->toContain("performance/alerts/{alert}/reconciliation")
        ->toContain("->whereUuid('alert')->middleware('permission:sales.performance.view')");
});

