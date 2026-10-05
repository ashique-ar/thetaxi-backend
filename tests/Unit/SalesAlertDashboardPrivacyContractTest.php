<?php

it('keeps frozen alert policy contracts out of the dashboard response', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));
    $performanceController = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPerformanceController.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-performance/sales-performance.component.html'));
    $alertsStart = strpos($performanceController, 'public function alerts(');
    $alertsEnd = strpos($performanceController, 'public function reconcileAlert(', $alertsStart);
    $alerts = substr($performanceController, $alertsStart, $alertsEnd - $alertsStart);

    expect($controller)
        ->toContain("'alert.threshold_snapshot'", "'alert.comparison_snapshot'")
        ->not->toContain('alert.policy_contract_snapshot', "'policy_contract_snapshot'")
        ->and($template)
        ->toContain('item.threshold_snapshot', 'item.comparison_snapshot')
        ->not->toContain('policy_contract_snapshot')
        ->and($alerts)
        ->toContain("'id' => \$alert->id", "'explanation' => \$alert->explanation")
        ->toContain('setCollection(', "config('sales.features.performance_alert_evaluations', false)")
        ->not->toContain('sales_profile_id', 'policy_contract_snapshot', 'evidence_snapshot',
            'evaluation_checksum', 'assigned_to', 'policy_version_id');
});

it('returns minimal alert and policy write confirmations instead of frozen policy or actor fields', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPerformanceController.php'));

    expect($controller)
        ->toContain(
            "private function writeConfirmation(object $row): array",
            "'id' => (string) \$row->id",
            "'status' => (string) \$row->status",
            "'version' => (int) (\$row->event_version ?? \$row->version)",
            '$this->writeConfirmation($performance->actOnAlert(',
            '$this->writeConfirmation($performance->storeAlertPolicy(',
            '$this->writeConfirmation($policies->approvePolicy(',
        )
        ->not->toContain(
            "'data' => \$performance->actOnAlert(",
            "'data' => \$performance->storeAlertPolicy(",
            "'data' => \$performance->approveAlertPolicy(",
        );
});
