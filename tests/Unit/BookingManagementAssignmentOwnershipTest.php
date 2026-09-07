<?php

use Illuminate\Support\Str;

it('treats a selected booking item as the authoritative assignment owner', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/Api/AssignmentController.php');
    $detailsMethod = Str::between(
        $source,
        'public function getAssignmentDetails(',
        'private function buildTrackingPayload('
    );

    expect($detailsMethod)
        ->toContain('$selectedVehicle = $selectedBookingItem')
        ->toContain('? $selectedBookingItem->vehicle')
        ->toContain('$selectedDriver = $selectedBookingItem')
        ->toContain('? $selectedBookingItem->driver')
        ->not->toContain('$selectedBookingItem?->vehicle ?? $booking->vehicle')
        ->not->toContain('$selectedBookingItem?->driver ?? $booking->driver');

    expect($detailsMethod)
        ->toContain('(string) $assignment->booking_item_id === (string) $selectedBookingItem->id')
        ->not->toContain('if ($filteredDriverAssignments->isNotEmpty())');
});

it('persists Angular booking item assignments during direct confirmation', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/BookingFlowService.php');
    $confirmation = Str::between(
        $source,
        'public function confirmBooking(',
        'private function createMultiGroupBookingItems('
    );

    expect($confirmation)
        ->toContain("elseif (!empty(\$params['booking_items']) && is_array(\$params['booking_items']))")
        ->toContain("foreach (\$params['booking_items'] as \$itemData)")
        ->toContain('$this->createSingleGroupBookingItem($booking, $itemData, $itemPricing)')
        ->toContain("\$booking->bookingItems()->update(['status' => \$booking->status])");
});

it('creates vehicle and driver assignment records for every booking item', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/BookingFlowService.php');
    $assignmentMethod = Str::between(
        $source,
        'protected function createBookingAssignments(',
        'protected function determineAssignmentType('
    );

    expect($assignmentMethod)
        ->toContain('?BookingItem $onlyBookingItem = null')
        ->toContain("\$booking->bookingItems()->with('serviceType')->get()")
        ->toContain('foreach ($bookingItems as $bookingItem)')
        ->toContain('$this->createBookingAssignments($booking, $params, $status, $bookingItem)')
        ->toContain("'booking_item_id' => \$bookingItem->id");
});
