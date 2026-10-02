<?php

it('keeps draft booking selection bounded readable and identical to write scope', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesCrmController.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-opportunities/sales-opportunities.component.html'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-opportunities/sales-opportunities.component.ts'));

    expect($controller)->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']", 'COALESCE(owner_staff.company_id, creator_staff.company_id)', 'COALESCE(owner_staff.id, creator_staff.id)', "where('context_type', 'staff')", "where('is_active', true)")
        ->and(substr_count($controller, '$this->linkableBookingQuery($opportunity)'))->toBe(2)
        ->and($template)->toContain('opportunity-booking-options')->not->toContain('row.booking_number||row.id')
        ->and($component)->toContain("'Unavailable Sales Profile'")->not->toContain(" : id; }");
});

it('uses bounded scoped owner selectors instead of loading all Sales Profiles', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesCrmController.php'));
    $service = file_get_contents(app_path('Services/Sales/SalesCrmService.php'));
    $route = file_get_contents(base_path('routes/api.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-opportunities/sales-opportunities.component.html'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-opportunities/sales-opportunities.component.ts'));

    expect($controller)->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']", "'sales.crm.manage-all'", "'sales.crm.manage-team'", "'setting.feature_key', 'crm'")
        ->and($route)->toContain("'opportunity-owner-options'")
        ->and($service)->toContain('lockActiveOpportunityOwner', "where('context_type', 'staff')", "where('is_active', true)", 'Opportunity owner must have an active Staff context.')
        ->and(substr_count($service, '$this->lockActiveOpportunityOwner('))->toBe(3)
        ->and($template)->toContain('endpoint="/sales/opportunity-owner-options"')->toContain('[queryParams]="transferOwnerQueryParams"')->not->toContain('profiles()')
        ->and($component)->not->toContain('crmEnabledByCompany')->not->toContain('transferProfiles()');
});

it('keeps opportunity source references searchable scoped and privacy limited', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesCrmController.php'));
    $service = file_get_contents(app_path('Services/Sales/SalesCrmService.php'));
    $route = file_get_contents(base_path('routes/api.php'));
    $page = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-opportunities/sales-opportunities.component.ts'));
    $picker = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-opportunity-source-picker/sales-opportunity-source-picker.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-opportunity-source-picker/sales-opportunity-source-picker.component.html'));

    expect($controller)->toContain('function opportunitySourceOptions', "'max:50'", 'sourceUserIds($request)', "'source.deleted_at'", "'opportunity.id'", "'source.email', 'source.phone'")
        ->and($route)->toContain("'opportunity-source-options'")
        ->and($service)->toContain('array $sourceUserIds', 'Inquiry source is outside your authorised Sales scope', 'Phone Call source is outside your authorised Sales scope', 'only one managed source record')
        ->and($page)->toContain("this.form.source === 'inquiry'", "this.form.source === 'phone_call'")
        ->and($picker)->not->toContain('opportunityAdministrationContext')->toContain('selectSource(option')
        ->and($template)->toContain('endpoint="/sales/opportunity-source-options"')->toContain('(recordSelected)="selectSource($event)"')->toContain('(ngModelChange)="clearSource($event)"')
        ->and($service)->toContain('Inquiry::query()->lockForUpdate()', 'PhoneCall::query()->lockForUpdate()', 'This Phone Call is already linked to an opportunity.');
});
