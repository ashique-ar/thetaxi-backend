<?php

it('renders the scoped Sales attribution booking number without an ID fallback', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesBookingAttributionController.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-attribution-operations/sales-attribution-operations.component.html'));

    expect($controller)->toContain("'booking:id,booking_number,payment_status,payment_collection_status'", 'applyActorScope')
        ->and($template)->toContain('row.booking?.booking_number', 'Booking reference unavailable')
        ->not->toContain('row.booking?.booking_number || row.booking_id');
});
