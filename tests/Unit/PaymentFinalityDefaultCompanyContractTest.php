<?php

it('defaults missing payment-finality company selections before enforcing scope', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/PaymentFinalityController.php'));

    foreach (['index', 'receipts', 'store'] as $method) {
        $start = strpos($controller, "public function {$method}(");
        $next = strpos($controller, 'public function ', $start + 1);
        $body = substr($controller, $start, $next === false ? null : $next - $start);

        expect($body)
            ->toContain("'company_id' => ['nullable', 'uuid', 'exists:companies,id']")
            ->toContain('resolveCompanyId($request, $data[\'company_id\'] ?? null)')
            ->toContain('assertCompanyScope($request, $data[\'company_id\'])');
    }

    expect($controller)
        ->toContain('activeDefaultCompany()?->id')
        ->toContain("abort_unless(\$resolved, 409")
        ->toContain("->where('is_active', true)")
        ->toContain('->lockForUpdate()->first()');
});
