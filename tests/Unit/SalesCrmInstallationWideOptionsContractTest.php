<?php

it('keeps opportunity owner and source selection independent of location context', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesCrmController.php'));

    foreach (['opportunityOwnerOptions', 'opportunitySourceOptions', 'createOpportunity'] as $method) {
        $start = strpos($controller, "public function {$method}(");
        $next = strpos($controller, 'public function ', $start + 1);
        $body = substr($controller, $start, $next === false ? null : $next - $start);

        expect($body)->not->toContain('company_id', 'resolveCompanyId(', 'activeDefaultCompany');
    }
});
