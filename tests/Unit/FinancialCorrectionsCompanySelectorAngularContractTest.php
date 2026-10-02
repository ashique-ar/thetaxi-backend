<?php

it('uses an explicit bounded Sales-scoped company selector for financial corrections', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/BookingPaymentAdjustmentController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-financial-corrections/sales-financial-corrections.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-financial-corrections/sales-financial-corrections.component.html'));

    expect($routes)->toContain("Route::get('payment-adjustment-company-options', [BookingPaymentAdjustmentController::class, 'companyOptions'])")
        ->and($controller)->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']", "'company_id' => ['required', 'uuid', 'exists:companies,id']")
        ->toContain("where('id', \$data['company_id'])->whereNull('deleted_at')->exists()")
        ->and($component)->toContain('UiManagedRecordSelectComponent', 'revision!==this.candidateLoadRevision', 'clearCandidateState()')
        ->not->toContain('companies = signal', "company_id:this.companyId||undefined")
        ->and($template)->toContain('endpoint="/sales/payment-adjustment-company-options"', 'title="Select a legal entity"')
        ->not->toContain('*ngFor="let company of companies()"');
});
