<?php

uses(Tests\TestCase::class);

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
        ->toContain("'payment_pending', 'payment_attention'");
});

it('returns queue counts from the same canonical predicate owner', function () {
    $service = file_get_contents(app_path('Services/BookingFlowService.php'));

    expect($service)
        ->toContain("'queue_counts' => \$this->getBookingQueueCounts")
        ->toContain('private function getBookingQueueCounts(')
        ->toContain('$this->applyOperationsQueueFilter($queueQuery, $queue)');
});
