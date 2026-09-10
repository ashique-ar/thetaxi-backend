<?php

it('uses an authorized bounded booking selector for SMS previews', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sms/SmsManagementController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/admin/system/sms/pages/sms-overview-page.component.html'));
    $page = file_get_contents(base_path('../portal-thetaxi/src/app/modules/admin/system/sms/pages/sms-overview-page.component.ts'));
    $messages = file_get_contents(base_path('../portal-thetaxi/src/app/modules/admin/system/sms/pages/sms-messages-page.component.html'));
    $detail = file_get_contents(base_path('../portal-thetaxi/src/app/modules/admin/system/sms/pages/sms-message-detail-dialog.component.ts'));
    $send = file_get_contents(base_path('../portal-thetaxi/src/app/modules/admin/system/sms/pages/sms-send-page.component.html'));

    expect($controller)->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']", "'booking_id' => ['nullable', 'uuid']", "can('bookings.view')", 'previewBooking(', "'value' => (string) \$booking->id")
        ->and($routes)->toContain("Route::get('booking-options'")
        ->and($component)->toContain('endpoint="/sms/booking-options"', 'Search booking number or log code')->not->toContain('Booking ID or booking number')
        ->and($page)->toContain('UiManagedRecordSelectComponent', 'booking_id: this.adminSummaryPreviewReference.value', 'booking_id: this.templatePreviewReference.value')
        ->and($messages)->toContain('booking_label', 'booking_item_label')->not->toContain("booking_id | slice", "booking_item_id | slice")
        ->and($detail)->toContain('booking_label', 'booking_item_label')->not->toContain('data.message.booking_id ||', 'data.message.booking_item_id ||')
        ->and($send)->not->toContain('Context ID', 'formControlName="context_id"');
});
