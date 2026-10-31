<?php

it('lists opportunities without a company selector or tenant filter', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesCrmController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-opportunities/sales-opportunities.component.html'));
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/services/sales.service.ts'));
    $start = strpos($controller, 'public function opportunities(');
    $end = strpos($controller, 'public function showOpportunity(', $start);
    $list = substr($controller, $start, $end - $start);

    expect($list)
        ->not->toContain("'company_id' =>", 'resolveCompanyId', 'active legal entity', 'CompanyIdScope::constrain')
        ->toContain("'sales.crm.view-all'", "'sales.crm.view-team'", 'constrainBookingOwnerToOpportunity')
        ->and($routes)->not->toContain('opportunity-company-options')
        ->and($template)->not->toContain('opportunity-company-options', 'opportunityCompanyFilter', 'Select an authorized active company')
        ->and($service)->not->toContain('opportunityCompanyOptions');
});
