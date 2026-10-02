<?php

it('uses scoped booking numbers in Sales collection work and submission rows', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CollectionScheduleWorkflowController.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-collections/sales-collections.component.html'));

    expect($controller)->toContain("'booking:id,booking_number,customer_id'", "'booking:id,booking_number'", "'booking_number' => \$row->booking?->booking_number", 'applyProfileScope')
        ->and($template)->toContain("row.booking_number || 'Booking reference unavailable'")
        ->not->toContain('row.booking_number||row.booking_id');
});
