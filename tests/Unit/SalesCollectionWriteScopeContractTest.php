<?php

it('holds the booking and current attribution scope through collection schedule commands', function (): void {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CollectionScheduleWorkflowController.php'));

    expect(substr_count($controller, 'withinBookingManagementScope($request, $booking'))
        ->toBe(4)
        ->and($controller)
        ->toContain("Booking::query()->whereKey(\$booking->id)->lockForUpdate()->firstOrFail()")
        ->toContain("SalesBookingAttribution::query()->where('booking_id', \$booking->id)->lockForUpdate()->firstOrFail()")
        ->toContain("'sales.collections.view-team'");
});

it('holds the booking and attribution scope through payment and commercial adjustment commands', function (): void {
    $paymentAdjustments = file_get_contents(app_path('Http/Controllers/Api/Sales/BookingPaymentAdjustmentController.php'));
    $attribution = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesBookingAttributionController.php'));

    expect(substr_count($paymentAdjustments, 'withinBookingScope($request, $booking'))
        ->toBe(4)
        ->and($paymentAdjustments)
        ->toContain("Booking::query()->whereKey(\$booking->id)->lockForUpdate()->firstOrFail()")
        ->toContain("SalesBookingAttribution::query()->where('booking_id', \$booking->id)->lockForUpdate()->firstOrFail()")
        ->and(substr_count($attribution, 'withinCommercialAdjustmentScope($request, $attribution'))
        ->toBe(2)
        ->and($attribution)
        ->toContain('Booking::query()->whereKey($candidate->booking_id)->lockForUpdate()->firstOrFail()')
        ->toContain('SalesBookingAttribution::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail()');
});

it('fails closed when collection history has missing or conflicting company ownership', function (): void {
    $integrity = file_get_contents(app_path('Services/Sales/SalesCollectionCompanyIntegrity.php'));
    $scheduleController = file_get_contents(app_path('Http/Controllers/Api/Sales/CollectionScheduleWorkflowController.php'));
    $paymentAdjustmentController = file_get_contents(app_path('Http/Controllers/Api/Sales/BookingPaymentAdjustmentController.php'));
    $attributionController = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesBookingAttributionController.php'));
    $rollingScheduleService = file_get_contents(app_path('Services/Sales/RollingPaymentScheduleService.php'));

    expect($integrity)
        ->toContain("'booking_payment_schedules'")
        ->toContain("'booking_payment_schedule_revisions'")
        ->toContain("'booking_payment_schedule_rules'")
        ->toContain("'booking_collection_work_items'")
        ->toContain("'booking_collection_submissions'")
        ->toContain("'booking_payment_receipts'")
        ->toContain("'booking_payment_adjustments'")
        ->toContain("'booking_commercial_value_adjustments'")
        ->toContain("whereNull('company_id')->orWhere('company_id', '!=', \$companyId)")
        ->and($scheduleController)->toContain('companyIntegrity->assertConsistent')
        ->and($paymentAdjustmentController)->toContain('companyIntegrity->assertConsistent')
        ->and($attributionController)->toContain('collectionCompanyIntegrity->assertConsistent')
        ->and($rollingScheduleService)->toContain('companyIntegrity->assertConsistent');
});
