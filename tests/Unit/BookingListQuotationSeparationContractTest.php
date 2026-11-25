<?php

it('keeps quotation records out of the all-bookings view and bookings out of quotations', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Booking/Traits/BookingSubmissionTrait.php'));
    $service = file_get_contents(app_path('Services/BookingFlowService.php'));
    $portal = file_get_contents(base_path('../portal-thetaxi/src/app/modules/booking/components/booking-list/booking-list.component.ts'));

    expect($controller)
        ->toContain("if (\$request->boolean('quotation_view'))")
        ->toContain("\$filters['status'] = ['quotation_requested'];")
        ->toContain("\$filters['exclude_quotation_requested'] = true;");

    expect($service)
        ->toContain("\$query->whereHas('booking', fn (\$bookingQuery) => \$bookingQuery->where('status', '!=', 'quotation_requested'))");

    expect($portal)
        ->toContain("quotation_view: this.managementView === 'quotations'");
});
