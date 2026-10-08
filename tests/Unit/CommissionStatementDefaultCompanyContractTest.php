<?php

it('defaults missing commission-statement company selections before authorization', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionStatementController.php'));
    $service = file_get_contents(app_path('Services/Sales/CommissionStatementService.php'));

    foreach (['index', 'profileOptions', 'schedule', 'disputes', 'payouts'] as $method) {
        $start = strpos($controller, "public function {$method}(");
        $next = strpos($controller, 'public function ', $start + 1);
        $body = substr($controller, $start, $next === false ? null : $next - $start);

        expect($body)
            ->toContain("'company_id' => ['nullable', 'uuid'")
            ->toContain('resolveCompanyId(');
    }

    $inputStart = strpos($controller, 'private function statementInput(');
    $inputEnd = strpos($controller, 'private function previewProjection(', $inputStart);
    $input = substr($controller, $inputStart, $inputEnd - $inputStart);

    expect($input)
        ->toContain("'company_id' => ['nullable', 'uuid'")
        ->toContain('resolveCompanyId(')
        ->and($controller)
        ->toContain('activeDefaultCompany()?->id')
        ->toContain("abort_unless(\$resolved, 409")
        ->toContain('assertManagementCompany(')
        ->and($service)
        ->toContain('lockActiveCompany((string) $companyId)')
        ->toContain("->where('is_active', true)", '->lockForUpdate()->first([\'id\'])');
});
