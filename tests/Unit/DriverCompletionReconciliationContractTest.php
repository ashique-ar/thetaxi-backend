<?php

it('reconciles completed driver assignments through item-scoped canonical completion', function () {
    $service = file_get_contents(app_path('Services/BookingLifecycleService.php'));
    $method = Str::between(
        $service,
        'public function reconcileCompletedDriverAssignment(',
        'public function completeBooking('
    );

    expect($method)
        ->toContain("TripPhase::COMPLETED->value")
        ->toContain("'completed_by_driver' => true")
        ->toContain('$this->completeBooking(')
        ->toContain('(string) $bookingItem->id');
});

it('provides a bounded repair command for existing inconsistent hires', function () {
    $command = file_get_contents(app_path('Console/Commands/ReconcileCompletedDriverHires.php'));

    expect($command)
        ->toContain('bookings:reconcile-driver-completions')
        ->toContain('reconcileCompletedDriverAssignment')
        ->toContain("whereNotNull('trip_completed_at')")
        ->toContain('limit($limit)');
});
