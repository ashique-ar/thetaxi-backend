<?php

it('filters and groups financial reports by completion time with a legacy timestamp fallback', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/ReportsController.php'));

    expect($controller)
        ->toContain("\$completedBookings = Booking::query()->where('status', 'completed')")
        ->toContain("\$this->applyFilters(\$completedBookings, \$filters, 'bookings.completed_at')")
        ->toContain("\$this->applyFilters(\$query, \$filters, 'bookings.completed_at')")
        ->toContain("\$this->getDateFormat(\$period, 'completed_at')")
        ->toContain("\$this->getDateFormat('day', 'completed_at')")
        ->toContain("COALESCE(bookings.completed_at, bookings.created_at)")
        ->toContain("'weekly'  => \"TO_CHAR")
        ->toContain("IYYY-\\\"W\\\"IW")
        ->toContain("'period' => ['sometimes', 'in:daily,weekly,monthly,yearly']")
        ->toContain("\$this->applyFilters(\$query, \$filters, 'bookings.completed_at');");
});

it('keeps cached dashboard date buckets isolated by user timezone', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/ReportsController.php'));

    expect($controller)->toContain("md5(json_encode([\$filters, TimezoneService::getUserTimezone()]))");
});
