<?php

it('defines a governed monthly close preview and separately authorised reopen command', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPerformanceController.php'));
    $permissions = file_get_contents(database_path('seeders/AllPermissionsSeeder.php'));

    expect($routes)
        ->toContain("performance/period-close-preview")
        ->toContain("performance/period-close")
        ->toContain("performance/period-locks/{periodLock}/reopen")
        ->toContain("permission:sales.performance.snapshots.reopen")
        ->and($controller)
        ->toContain('$this->assertCompanyWideScope($request, $data[\'company_id\'])')
        ->toContain("'expected_lock_version' => ['required', 'integer', 'min:1']")
        ->toContain("'preview_checksum' => [\$commit ? 'required' : 'nullable', 'string', 'size:64']")
        ->and($permissions)
        ->toContain("'sales.performance.snapshots.reopen'")
        ->toContain('denyByDefaultPermissions');
});

it('freezes reconciled source evidence and preserves superseded snapshots', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesPeriodCloseService.php'));
    $performance = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_14_100000_create_sales_period_close_workflow.php'));

    expect($service)
        ->toContain("'write_performed' => false")
        ->toContain("'metric_row_reconciliation_failed'")
        ->toContain("'approved_alert_policy_missing'")
        ->toContain("'legacy_frozen_snapshot_unlinked'")
        ->toContain("'profile_staff_missing'")
        ->toContain("'source_reconciliation_snapshot' => \$preview['reconciliation']")
        ->toContain("hash_equals(\$lockedPreview['preview_checksum'], \$previewChecksum)")
        ->toContain("in_array(\$lock->state, ['open', 'reopened'], true)")
        ->toContain("\$prior->update(['status' => 'superseded'])")
        ->and($performance)
        ->toContain('Direct snapshot generation is disabled')
        ->toContain("\$row['display_rank'] = null")
        ->and($migration)
        ->toContain("'sales_period_close_events'")
        ->toContain("'supersedes_snapshot_id'")
        ->toContain('Rollback refused: export and reconcile immutable Sales period-close evidence first.');
});

it('serialises metric writers with the company close lock and blocks locked-period inserts', function () {
    $facts = file_get_contents(app_path('Services/Sales/SalesMetricFactService.php'));
    $dashboard = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPerformanceController.php'));

    expect($facts)
        ->toContain("DB::table('companies')->whereKey(\$payload['company_id'])->lockForUpdate()")
        ->toContain("->where('state', 'locked')")
        ->toContain('use the governed reopen and rebuild workflow')
        ->and($dashboard)->toContain("->where('alert_snapshot.status', 'frozen')")
        ->and($controller)->toContain("SalesPerformanceAlert::query()->whereIn('snapshot_id'")
        ->toContain("->where('status', 'frozen')->select('id')");
});
