<?php

it('lists collection schedules installation-wide without a company selector', function (): void {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CollectionScheduleWorkflowController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $portal = dirname(base_path()).'/portal-thetaxi/src/app/modules/sales/components/sales-collection-schedules';
    $template = file_get_contents($portal.'/sales-collection-schedules.component.html');
    $component = file_get_contents($portal.'/sales-collection-schedules.component.ts');

    expect($controller)
        ->toContain('public function scheduleBookings(Request $request)')
        ->toContain('$this->scheduleProfileIds($request)')
        ->not->toContain('scheduleCompanyOptions', 'company_id', 'resolveCompanyId')
        ->and($routes)
        ->not->toContain('collection-schedule-company-options')
        ->and($template)
        ->not->toContain('collection-schedule-company-options', 'companyControl', 'Choose a company')
        ->and($component)
        ->toContain('this.api.scheduleBookings()')
        ->not->toContain('company_id', 'companyControl', 'companyId');
});
