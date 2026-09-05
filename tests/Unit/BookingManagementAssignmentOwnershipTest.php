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
});
