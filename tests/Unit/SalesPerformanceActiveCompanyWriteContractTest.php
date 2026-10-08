<?php

it('locks an active company before Sales target, import, alert-policy, and alert-evaluation writes', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));
    $methods = [
        ['public function createTarget(', 'public function approveTarget(', 'SalesProfile::query()->lockForUpdate()'],
        ['public function approveTarget(', 'public function previewTargetCopy(', 'SalesTargetVersion::query()->lockForUpdate()'],
        ['public function copyTargets(', 'public function previewTargetImport(', 'SalesTargetCopyBatch::query()->where'],
        ['public function importTargets(', 'public function preview(', "where('job_type', 'sales_target_csv')"],
        ['public function storeAlertPolicy(', 'private function activeAlertOwnerIdentity(', 'SalesAlertPolicyVersion::query()'],
        ['public function approveAlertPolicy(', 'public function actOnAlert(', 'SalesAlertPolicyVersion::query()->lockForUpdate()'],
        ['public function evaluateSnapshotAlerts(', null, 'SalesKpiSnapshot::query()->with'],
    ];

    foreach ($methods as [$startMarker, $endMarker, $businessWrite]) {
        $start = strpos($service, $startMarker);
        $end = $endMarker ? strpos($service, $endMarker, $start + 1) : strlen($service);
        $method = substr($service, $start, $end - $start);

        expect(strpos($method, "DB::table('companies')"))->toBeLessThan(strpos($method, $businessWrite))
            ->and($method)->toContain("where('is_active', true)", "whereNull('deleted_at')->lockForUpdate()");
    }
});

it('locks an active company before period close and reopen business rows', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesPeriodCloseService.php'));
    $close = substr($service, strpos($service, 'public function close('), strpos($service, 'public function reopen(') - strpos($service, 'public function close('));
    $reopen = substr($service, strpos($service, 'public function reopen('));

    expect(strpos($close, "DB::table('companies')"))->toBeLessThan(strpos($close, "DB::table('sales_period_close_events')"))
        ->and(strpos($reopen, "DB::table('companies')"))->toBeLessThan(strpos($reopen, "DB::table('domain_period_locks')->whereKey($periodLockId)->where('domain', 'sales')->lockForUpdate()"))
        ->and($close)->toContain("where('is_active', true)", "whereNull('deleted_at')->lockForUpdate()")
        ->and($reopen)->toContain("where('is_active', true)", "whereNull('deleted_at')->lockForUpdate()");
});

it('requires an active company before recording Sales metric facts', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesMetricFactService.php'));
    $record = substr($service, strpos($service, 'public function record('), strpos($service, 'public function projectBookingConfirmation(') - strpos($service, 'public function record('));

    expect(strpos($record, "DB::table('companies')"))->toBeLessThan(strpos($record, 'SalesMetricFact::query()'))
        ->and(strpos($record, 'SalesMetricFact::query()'))->toBeLessThan(strpos($record, 'SalesMetricFact::create('))
        ->and($record)->toContain("where('is_active', true)", "whereNull('deleted_at')->lockForUpdate()");
});
