<?php

it('uses bounded scoped company selectors throughout performance administration without UUID label fallbacks', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPerformanceController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-performance-administration/sales-performance-administration.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-performance-administration/sales-performance-administration.component.html'));

    expect($routes)->toContain("Route::get('performance/company-options', [SalesPerformanceController::class, 'companyOptions'])")
        ->and($controller)->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']")
        ->toContain("\$this->scope->profileIds(")
        ->toContain("where('status', 'active')->activeAt(now())")
        ->toContain("whereNull('deleted_at')->whereIn('id', \$companyIds)")
        ->and(substr_count($template, 'endpoint="/sales/performance/company-options"'))->toBe(7)
        ->and($template)->not->toContain('name="targetFilterCompany"\n            (selectionChange)', '*ngFor="let row of companies()" [value]="row.id"')
        ->and($component)->toContain('UiManagedRecordSelectComponent', "'Unavailable legal entity'", "'Unavailable Sales Profile'")
        ->toContain('contextRequestVersion', 'companyLabels', 'default_company_id', 'selectCompany')
        ->not->toContain('?.name || id', ': id; }', 'companies()[0]', 'data?.companies');
    expect($routes)->toContain("Route::get('performance/profile-options', [SalesPerformanceController::class, 'profileOptions'])")
        ->and($controller)->toContain("'selected_ids' => ['sometimes', 'array', 'max:100']", 'profileOptionsPayload', 'scopeProfiles(', "compact('companyLabels', 'policies')")
        ->and($template)->toContain('UiManagedRecordMultiSelectComponent', 'endpoint="/sales/performance/profile-options"', 'row.profile_label', '*ngIf="target.company_id"', '*ngIf="copy.company_id"')
        ->and($component)->toContain('targetEntryCompanyChanged()', 'copyCompanyChanged()')
        ->and($component)->not->toContain('profiles = signal', 'this.profiles()', 'copyProfiles()', 'targetFilterProfiles()');
    $singleSelector = file_get_contents(base_path('../portal-thetaxi/src/app/shared/components/ui/managed-record-select/managed-record-select.component.ts'));
    $multiSelector = file_get_contents(base_path('../portal-thetaxi/src/app/shared/components/ui/managed-record-multi-select/managed-record-multi-select.component.ts'));
    expect($singleSelector)->toContain('contextRevision', 'revision !== this.contextRevision')
        ->and($multiSelector)->toContain('contextRevision', 'values.some(value => !this.values.includes(value))');
});
