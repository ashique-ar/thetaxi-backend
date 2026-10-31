<?php

it('writes Sales targets and alerts installation-wide with Staff and record locks', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));
    $targetStart = strpos($service, 'public function createTarget(');
    $targetEnd = strpos($service, 'public function approveTarget(', $targetStart);
    $target = substr($service, $targetStart, $targetEnd - $targetStart);
    $periodClose = file_get_contents(app_path('Services/Sales/SalesPeriodCloseService.php'));

    expect($target)->toContain('SalesProfile::query()->lockForUpdate()', 'Staff::query()', 'SalesTargetVersion::create(')
        ->and($service)->not->toContain("DB::table('companies')", 'company_id', 'defaultCompanyId()')
        ->and($periodClose)->not->toContain("DB::table('companies')", 'company_id', 'defaultCompanyId()');
});

it('records installation-wide Sales metric facts without company context', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesMetricFactService.php'));
    $record = substr($service, strpos($service, 'public function record('), strpos($service, 'public function projectBookingConfirmation(') - strpos($service, 'public function record('));

    expect($record)->not->toContain("DB::table('companies')", "'company_id' =>", 'defaultCompanyId()')
        ->and($record)->toContain('SalesMetricFact::query()', 'SalesMetricFact::create(', 'sameFacts(');
});
