<?php

it('loads financial correction candidates and recovery cases without company selection', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/BookingPaymentAdjustmentController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-financial-corrections/sales-financial-corrections.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-financial-corrections/sales-financial-corrections.component.html'));

    expect($routes)->not->toContain('payment-adjustment-company-options', 'commission-recoveries/company-options')
        ->and($controller)->not->toContain('company_id', 'resolveCompanyId', 'CompanySettingsAccessService')
        ->and($component)->toContain('revision!==this.candidateLoadRevision', 'loadCandidates()', 'loadRecoveries(1)')
        ->not->toContain('UiManagedRecordSelectComponent', 'paymentAdjustmentCompanyOptions()', 'recoveryCompanyId', 'company_id:')
        ->and($template)->not->toContain('payment-adjustment-company-options', 'commission-recoveries/company-options', 'Select a company');
});
