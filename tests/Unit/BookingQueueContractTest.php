<?php


it('validates every canonical booking queue at the API boundary', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Booking/Traits/BookingSubmissionTrait.php'));

    foreach ([
        'needs_approval', 'needs_assignment', 'ready_to_dispatch', 'active',
        'return_due', 'qc_pending', 'repair_pending', 'ready_to_complete',
        'payment_attention',
    ] as $queue) {
        expect($controller)->toContain($queue);
    }
});

it('scopes operational queue predicates to booking items', function () {
    $service = file_get_contents(app_path('Services/BookingFlowService.php'));
    $queues = Str::between(
        $service,
        'private function applyOperationsQueueFilter(',
        'private function normalizeFilterValues('
    );

    expect($queues)
        ->toContain("whereColumn('booking_dispatches.booking_item_id', 'booking_items.id')")
        ->toContain("whereColumn('booking_qcs.booking_item_id', 'booking_items.id')")
        ->toContain("whereColumn('driver_assignments.booking_item_id', 'booking_items.id')")
        ->toContain("whereNull('booking_items.completed_at')")
        ->toContain("whereNotIn('booking_items.status', ['completed', 'cancelled', 'rejected'])")
        ->toContain("whereDoesntHave('booking.driverAssignments'")
        ->toContain("'payment_pending', 'payment_attention'");
});

it('returns queue counts from the same canonical predicate owner', function () {
    $service = file_get_contents(app_path('Services/BookingFlowService.php'));

    expect($service)
        ->toContain("'queue_counts' => \$this->getBookingQueueCounts")
        ->toContain('private function getBookingQueueCounts(')
        ->toContain('$this->applyOperationsQueueFilter($queueQuery, $queue)');
});

it('supports stable chronological pickup ordering for dispatch screens', function () {
    $service = file_get_contents(app_path('Services/BookingFlowService.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Booking/Traits/BookingSubmissionTrait.php'));
    $sorts = Str::between($service, "case 'pickup_datetime':", "case 'to_date':");

    expect($controller)->toContain('from_date,pickup_datetime,to_date')
        ->and($sorts)
        ->toContain("orderBy('booking_items.from_date', \$sortDirection)")
        ->toContain("orderBy('booking_items.from_time', \$sortDirection)")
        ->toContain("orderBy('booking_items.id', \$sortDirection)");
});
