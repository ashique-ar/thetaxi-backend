<?php

it('scopes commission statements by Staff permissions and Sales Profile assignments', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionStatementController.php'));
    $service = file_get_contents(app_path('Services/Sales/CommissionStatementService.php'));

    expect($controller)
        ->toContain('private function applyScope(', 'private function visibleProfileIds(', 'sales.commission-statements.view-all', 'sales.commission-statements.view-team')
        ->not->toContain('company_id', 'resolveCompanyId(', 'activeDefaultCompany', 'assertManagementCompany(')
        ->and($service)
        ->not->toContain('company_id', 'lockActiveCompany(');
});
