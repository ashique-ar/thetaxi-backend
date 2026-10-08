<?php

it('defaults omitted CRM company selections while retaining scoped profile checks', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesCrmController.php'));

    foreach (['opportunityOwnerOptions', 'opportunitySourceOptions', 'activities', 'recordActivity', 'tasks', 'createOpportunity', 'createTask'] as $method) {
        $start = strpos($controller, "public function {$method}(");
        $next = strpos($controller, 'public function ', $start + 1);
        $body = substr($controller, $start, $next === false ? null : $next - $start);

        expect($body)
            ->toContain("'company_id' => ['nullable', 'uuid', 'exists:companies,id']")
            ->toContain('resolveCompanyId(');
    }

    expect($controller)
        ->toContain('activeDefaultCompany()?->id')
        ->toContain("abort_unless(\$resolved, 409")
        ->toContain('assertActiveListCompany(');
});
