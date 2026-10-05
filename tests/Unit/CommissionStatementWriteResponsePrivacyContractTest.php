<?php

it('projects commission write responses instead of serializing service models', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionStatementController.php'));

    expect($controller)
        ->toContain("'status' => \$statement->status", "'period_start' => \$statement->period_start", "'period_end' => \$statement->period_end")
        ->toContain("'status' => \$updated->status", "'state_version' => \$updated->state_version")
        ->toContain("'status' => \$dispute->status", "'status' => \$resolved->status")
        ->toContain("'status' => \$payout->status", "'accounting_status' => \$payout->accounting_status")
        ->toContain("'status' => \$reversal->status", "'accounting_status' => \$reversal->accounting_status")
        ->toContain("'id' => \$export->id, 'file_name' => \$export->file_name")
        ->not->toContain("'data' => \$statements->generate(", "'data' => \$statements->transition(", "'data' => \$disputes->raise(", "'data' => \$disputes->resolve(", "'data' => \$payouts->pay(", "'data' => \$payouts->reverse(");
});

it('preselects and enforces the authorized company across commission operation lists', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionStatementController.php'));
    $portal = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-commission-operations/sales-commission-operations.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-commission-operations/sales-commission-operations.component.html'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($controller)->toContain(
        'public function companyOptions', 'default_company_id', "'company_id' => ['required'", "where('is_active', true)",
        "where('company_id', \$companyId)", 'in_array($data[\'company_id\'], $companyIds, true)',
        "where('sales_profiles.company_id', \$companyId)", 'Select a Sales Profile in the chosen legal entity.',
    );
    expect($portal)->toContain(
        'companyChanged(companyId: string)', 'profileQueryParams', 'this.api.statements({ company_id: companyId',
        'this.api.disputes({ company_id: companyId', 'this.api.payouts({ company_id: companyId',
        'this.api.statementSchedule({ company_id: companyId', 'company_id: this.companyId', 'loadVersion',
    );
    expect($template)->toContain(
        'endpoint="/sales/commission-operations/company-options"',
        '[queryParams]="profileQueryParams"', '*ngIf="companyId"', 'Search authorized legal entities',
    );
    expect($routes)->toContain("commission-operations/company-options");
});
