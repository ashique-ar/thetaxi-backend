<?php

use Illuminate\Support\Str;

it('reconciles completed driver assignments through item-scoped canonical completion', function () {
    $service = file_get_contents(__DIR__ . '/../../app/Services/BookingLifecycleService.php');
    $method = Str::between(
        $service,
        'public function reconcileCompletedDriverAssignment(',
        'public function completeBooking('
    );

    expect($method)
        ->toContain("TripPhase::COMPLETED->value")
        ->toContain("'completed_by_driver' => true")
        ->toContain("'suppress_completion_emails' => true")
        ->toContain('resolveCompletedAssignmentBookingItem')
        ->toContain('Repaired completed driver assignment booking item link')
        ->toContain('$this->completeBooking(')
        ->toContain('(string) $bookingItem->id');
});

it('suppresses invoice emails for both single and aggregate reconciliation completion', function () {
    $service = file_get_contents(__DIR__ . '/../../app/Services/BookingLifecycleService.php');

    expect($service)
        ->toContain("if ((bool) (\$completionData['suppress_completion_emails'] ?? false))")
        ->toContain('private function runAggregateCompletionEffects(Booking $booking, bool $suppressCompletionEmails = false)')
        ->toContain("if (\$suppressCompletionEmails)");
});

it('provides a bounded repair command for existing inconsistent hires', function () {
    $command = file_get_contents(__DIR__ . '/../../app/Console/Commands/ReconcileCompletedDriverHires.php');

    expect($command)
        ->toContain('bookings:reconcile-driver-completions')
        ->toContain('reconcileCompletedDriverAssignment')
        ->toContain("whereNotNull('trip_completed_at')")
        ->toContain("whereDoesntHave('bookingItem')")
        ->toContain('limit($limit)');
});
