<?php

it('governs alert actions with optimistic versioning and checksum-bound replay', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPerformanceController.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_14_102000_govern_sales_performance_alert_actions.php'));

    expect($service)
        ->toContain("abort_unless(\$locked->event_version === \$expectedVersion, 409")
        ->toContain("hash_equals(\$replay->request_payload_checksum, \$checksum)")
        ->toContain("'sales.performance.alert_'.\$action")
        ->toContain("DB::table('sales_performance_alert_action_events')->insert")
        ->and($controller)
        ->toContain("Rule::in(['acknowledge', 'resolve', 'reopen', 'snooze'])")
        ->toContain("'expected_version' => ['required', 'integer', 'min:1']")
        ->and($migration)
        ->toContain('sales_alert_action_idempotency_unique')
        ->toContain('sales_alert_action_version_unique')
        ->toContain('Rollback refused: export and reconcile immutable Sales alert action evidence first.');
});

it('keeps snooze timezone-explicit and escalation separately authorized', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPerformanceController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $permissions = file_get_contents(database_path('seeders/AllPermissionsSeeder.php'));
    $dashboard = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));

    expect($controller)
        ->toContain("(Z|[+-]\\d{2}:\\d{2})")
        ->toContain("\$alert, 'escalate'")
        ->and($routes)
        ->toContain("'permission:sales.performance.alerts.escalate'")
        ->toContain("'sales.feature:performance_alert_actions'")
        ->and($permissions)->toContain("'sales.performance.alerts.escalate'")
        ->and($dashboard)
        ->toContain("config('sales.features.performance_alert_actions', false)")
        ->toContain("whereNull('alert.snoozed_until')");
});

it('retains the Staff boundary and never converts intervention evidence into discipline', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));
    $dashboard = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));

    expect($dashboard)
        ->toContain("join('staff', 'staff.id', '=', 'profile.staff_id')")
        ->and($service)->not->toContain('disciplin');
});
