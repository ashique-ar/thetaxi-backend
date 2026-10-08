<?php

it('defaults omitted Sales policy company selections before checking Staff scope', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPolicySettingsController.php'));

    foreach (['index', 'store', 'storeFeature', 'storeStaffCategory'] as $method) {
        $start = strpos($controller, "public function {$method}(");
        $next = strpos($controller, 'public function ', $start + 1);
        $body = substr($controller, $start, $next === false ? null : $next - $start);

        expect($body)
            ->toContain("'company_id' => ['nullable', 'uuid', 'exists:companies,id']")
            ->toContain('resolveCompanyId(')
            ->toContain('assertCompany($request, $data[\'company_id\'])');
    }

    expect($controller)
        ->toContain('activeDefaultCompany()?->id')
        ->toContain("abort_unless(\$resolved, 409")
        ->toContain('manage-all')
        ->toContain('actorCompanyIds($request)');
});
