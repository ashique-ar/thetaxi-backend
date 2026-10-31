<?php

it('keeps Sales configuration and workflow endpoints free of company tenant selectors', function () {
    foreach ([
        'CommissionConfigurationController.php', 'CommissionStatementController.php',
        'CollectionScheduleWorkflowController.php', 'PaymentFinalityController.php',
        'SalesBookingAttributionController.php', 'SalesCrmController.php',
        'SalesPerformanceController.php', 'SalesPolicySettingsController.php', 'SalesProfileController.php',
    ] as $controller) {
        expect(file_get_contents(app_path('Http/Controllers/Api/Sales/'.$controller)))
            ->not->toContain('company_id', 'companyId', 'default_company_id', 'resolveCompanyId(', 'activeDefaultCompany');
    }
});
