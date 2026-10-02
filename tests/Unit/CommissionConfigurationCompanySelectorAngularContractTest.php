<?php

it('uses a bounded active-Staff-scoped company selector for commission configuration', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionConfigurationController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-commission-configuration/sales-commission-configuration.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-commission-configuration/sales-commission-configuration.component.html'));

    expect($routes)->toContain("Route::get('commission-configuration/company-options', [CommissionConfigurationController::class, 'companyOptions'])")
        ->and($controller)->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']")
        ->toContain("whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now())")
        ->toContain("where('id', \$companyId)->whereNull('deleted_at')->exists()")
        ->and($component)->toContain('UiManagedRecordSelectComponent', 'resetTenantState()', 'revision !== this.loadRevision')->not->toContain('commissionConfigurationContext()', 'companies = signal')
        ->and($template)->toContain('endpoint="/sales/commission-configuration/company-options"', 'title="Select a legal entity"')
        ->not->toContain('*ngFor="let company of companies()"', 'item.row.created_by', '<td>{{row.id}}</td>');
    expect($component)->toContain('Unavailable calendar', 'Unavailable plan family', 'Unavailable Sales Profile', 'Unavailable employee', 'row.code || this.targetLabel(row)');
    expect($routes)->toContain("Route::get('commission-configuration/reference-options', [CommissionConfigurationController::class, 'referenceOptions'])\n            ->middleware('permission:sales.commission-config.manage');")
        ->and($template)->toContain('endpoint="/sales/commission-configuration/reference-options"', "record_type:'sales_profile'", "record_type:'employee'")
        ->not->toContain('references().profiles', 'references().staff');
    expect($controller)->toContain("\$this->validateScopedTarget(\$locked->only(['company_id', 'scope_type', 'staff_category', 'sales_profile_id', 'staff_id']))", "whereHas('staff'");
    expect($controller)->toContain("Rule::in(['sales_profile', 'employee', 'plan_family', 'cycle_version', 'approved_calendar', 'draft_calendar'])", "where('status', 'approved')")
        ->and($template)->toContain("record_type:'plan_family'", 'Search approved plan families')
        ->and($component)->not->toContain('approvedFamilies()');
    expect($template)->toContain("record_type:'cycle_version'", 'Search approved commission cycles')
        ->and($component)->not->toContain('approvedCycles()');
    expect($template)->toContain("record_type:'approved_calendar'", "record_type:'draft_calendar'", 'Search approved calendars', 'Search draft calendars')
        ->and($component)->not->toContain('approvedCalendars()', 'draftCalendars()');
    expect($routes)->toContain("Route::get('commission-configuration/version-options', [CommissionConfigurationController::class, 'versionOptions'])\n            ->middleware('permission:sales.commission-config.view');")
        ->and($controller)->toContain("where('family.company_id', \$data['company_id'])", "max:50")
        ->and($template)->toContain('endpoint="/sales/commission-configuration/version-options"', 'Formula version to preview');
});
