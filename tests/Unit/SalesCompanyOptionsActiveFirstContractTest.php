<?php

it('ranks active companies before inactive defaults across Sales company selectors', function () {
    foreach ([
        'BookingPaymentAdjustmentController.php',
        'CommissionConfigurationController.php',
        'PaymentFinalityController.php',
        'SalesBookingAttributionController.php',
        'SalesCollectionCompanyRepairController.php',
        'SalesPerformanceController.php',
        'SalesPolicySettingsController.php',
        'SalesProfileController.php',
    ] as $controller) {
        expect(file_get_contents(app_path('Http/Controllers/Api/Sales/'.$controller)))
            ->toContain("orderByDesc('is_active')->orderByDesc('is_default')");
    }
});
