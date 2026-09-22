<?php

use Illuminate\Support\Str;

it('exposes assignment details and audited operations notes in booking management', function () {
    $root = dirname(__DIR__, 2);
    $routes = file_get_contents($root . '/routes/api.php');
    $service = file_get_contents($root . '/app/Services/BookingFlowService.php');
    $controller = file_get_contents($root . '/app/Http/Controllers/Api/Booking/Traits/BookingSubmissionTrait.php');

    expect($routes)
        ->toContain("bookings/{bookingId}/items/{bookingItemId}/operations-notes")
        ->and($controller)->toContain('getOperationsNotes(', 'updateOperationsNotes(')
        ->and($service)->toContain("'vehicle_group' => \$itemVehicleGroup", "'notes' => \$item->notes")
        ->and($service)->toContain("->event('operations_note_updated')", "'booking_item_id' => (string) \$item->id")
        ->and(Str::substrCount($routes, 'operations-notes'))->toBe(2);
});
