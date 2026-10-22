<?php

it('groups joined report currencies by their explicit source expression', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/ReportsController.php'));

    expect($controller)
        ->toContain('->groupByRaw($serviceCurrency)')
        ->toContain("->groupByRaw(\"COALESCE(NULLIF(UPPER(booking_items.currency), ''), NULLIF(UPPER(bookings.currency), ''), 'LKR')\")");
});

it('exports booking trip dates from booking items', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/ReportsController.php'));

    expect($controller)
        ->toContain("Booking::with('bookingItems')")
        ->toContain("\$b->bookingItems->min('from_date')")
        ->toContain("\$b->bookingItems->max('to_date')")
        ->not->toContain("select('booking_number', 'status', 'from_date', 'to_date'");
});
