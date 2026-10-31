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

it('loads commission operations installation-wide while retaining Staff/profile permission scope', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionStatementController.php'));
    $portal = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-commission-operations/sales-commission-operations.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-commission-operations/sales-commission-operations.component.html'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($controller)->not->toContain('companyOptions', 'resolveCompanyId(', 'assertManagementCompany', 'company_id')
        ->and($controller)->toContain('visibleProfileIds', 'assertPayoutScope');
    expect($portal)->toContain('ngOnInit(): void { this.load(); }', 'this.api.statements({ per_page: 100 })')
        ->not->toContain('companyId', 'company_id', 'company-options');
    expect($template)->not->toContain('company-options', 'companyId', '[queryParams]')
        ->and($routes)->not->toContain('commission-operations/company-options');
});
