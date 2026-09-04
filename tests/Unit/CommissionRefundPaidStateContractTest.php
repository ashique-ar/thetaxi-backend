<?php

it('freezes paid-state evidence before an immutable refund recovery decision', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionRecoveryService.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_13_143000_freeze_commission_refund_paid_state.php'));

    expect($service)->toContain('previewDecision')
        ->toContain("'write_performed' => false")
        ->toContain("'paid_negative_carry_forward'")
        ->toContain("'unpaid_statement_liability_adjustment'")
        ->toContain("'unstatemented_liability_adjustment'")
        ->toContain('multiple non-void statements and requires reconciliation')
        ->toContain("hash_equals(\$lockedPreview['preview_checksum'], \$previewChecksum)")
        ->toContain('The source adjustment preparer or approver cannot decide its commission recovery.')
        ->toContain('The original beneficiary cannot decide their own commission recovery.')
        ->and($migration)->toContain("unsignedInteger('event_version')->default(1)")
        ->toContain('commission_recovery_statement_snapshot_check')
        ->toContain('Rollback refused: export and reconcile frozen commission refund paid-state decisions first.');
});

it('uses distinct deny-by-default refund decision and waiver authorities', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionRecoveryController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $permissions = file_get_contents(database_path('seeders/AllPermissionsSeeder.php'));

    expect($controller)->toContain("'sales.refunds.waive'")
        ->toContain("'sales.refunds.decide'")
        ->toContain('userHasAnyForInternalContext')
        ->and($routes)->toContain('commission-refund-reviews/{case}/preview')
        ->toContain('commission-refund-reviews/{case}/decision')
        ->and($permissions)->toContain("'sales.refunds.decide'")
        ->toContain("'sales.refunds.waive'");
});

it('projects recovery by occurrence period and exposes negative carry-forward on statements', function () {
    $statements = file_get_contents(app_path('Services/Sales/CommissionStatementService.php'));
    $metrics = file_get_contents(app_path('Services/Sales/SalesMetricFactService.php'));

    expect($statements)->toContain('Refund recovery from paid history — negative carry-forward')
        ->toContain("'closing_carry_forward_lkr' => min(0, \$balance)")
        ->toContain("where('sales_commission_recovery_decisions.approved_at', '<=', \$cutoffAt)")
        ->and($metrics)->toContain("'resolution_disposition' => \$recovery->resolution_disposition")
        ->toContain("'occurred_at' => \$adjustment->adjustment_effective_at");
});

it('recovers refunds from the actual immutable entitlement created after a hold', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionRecoveryService.php'));
    $metrics = file_get_contents(app_path('Services/Sales/SalesMetricFactService.php'));
    $releases = file_get_contents(app_path('Services/Sales/CommissionHoldService.php'));
    $holdAdjustments = file_get_contents(app_path('Services/Sales/CommissionHoldAdjustmentService.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_13_145000_freeze_commission_recovery_entitlement_source.php'));

    expect($service)->toContain("'commission_hold_release'")
        ->toContain("'commission_hold_adjustment'")
        ->toContain("\$earning->status === 'earned'")
        ->toContain("\$earning->status !== 'earned'")
        ->toContain('multiple entitlement sources and requires reconciliation before refund recovery')
        ->toContain("where('sales_commission_statement_lines.source_type', \$case->entitlement_source_type)")
        ->toContain('The frozen commission entitlement source is unavailable or no longer reconciles.')
        ->and($metrics)->toContain("'sales_profile_id' => \$case->beneficiary_sales_profile_id")
        ->toContain("'entitlement_source_id' => \$case->entitlement_source_id")
        ->and($releases)->toContain('openForExistingCashDecreases')
        ->toContain('}, 3);')
        ->and($holdAdjustments)->toContain('openForExistingCashDecreases')
        ->and($migration)->toContain('commission_recovery_entitlement_source_check')
        ->toContain('a legacy recovery is not linked to an actual earned entitlement')
        ->toContain('Rollback refused: export and reconcile frozen commission recovery entitlement-source evidence first.');
});
