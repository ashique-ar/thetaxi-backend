<?php

it('defaults omitted commission-configuration company selections before scope checks', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionConfigurationController.php'));

    foreach ([
        'referenceOptions', 'versionOptions', 'index', 'storeFamily', 'storeAssignment',
        'storeOverride', 'storeCycle', 'storeBusinessCalendar', 'storeCycleAssignment',
    ] as $method) {
        $start = strpos($controller, "public function {$method}(");
        $next = strpos($controller, 'public function ', $start + 1);
        $body = substr($controller, $start, $next === false ? null : $next - $start);

        expect($body)
            ->toContain("'company_id' => ['nullable', 'uuid', 'exists:companies,id']")
            ->toContain('resolveCompanyId(')
            ->toContain('assertCompanyScope(');
    }

    expect($controller)
        ->toContain('activeDefaultCompany()?->id')
        ->toContain("abort_unless(\$resolved, 409");
});
