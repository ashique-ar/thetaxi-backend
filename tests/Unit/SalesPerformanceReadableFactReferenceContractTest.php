<?php

it('keeps internal source IDs out of Sales Performance fact labels', function () {
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-performance/sales-performance.component.html'));

    expect($template)->toContain('Booking source', "['/bookings',fact.canonical_ids.booking_id]", '{{fact.source_type}}', '{{fact.source_event}}')
        ->not->toContain('{{fact.canonical_ids.booking_id}}', '{{fact.source_id}}');
});

it('uses a readable reference in the active portfolio without changing its access gate', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesPortfolioStatusService.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-performance/sales-performance.component.html'));

    expect($service)->toContain("'booking.booking_number'", "'canonical_record_access' => 'Opening Booking Management requires bookings.view")
        ->and($template)->toContain("row.booking_number || 'Booking reference unavailable'")
        ->not->toContain('row.booking_number||row.booking_id');
});
