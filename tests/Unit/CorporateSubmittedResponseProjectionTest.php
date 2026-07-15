<?php

use App\Models\Booking\BookingItem;
use App\Services\CorporateSubmittedResponseProjector;
use Illuminate\Support\Str;

it('allowlists submitted corporate responses without serializing booking metadata', function () {
    $item = (new ReflectionClass(BookingItem::class))->newInstanceWithoutConstructor();
    $item->setRawAttributes([
        'notes' => 'Meet at reception',
        'metadata' => json_encode([
            'passenger_count' => 3,
            'flight_number' => 'UL 101',
            'tracking_points' => [['latitude' => 1]],
            'driver_details' => ['phone' => 'secret'],
            'distance_details' => ['actual_distance' => 50],
            'payment_reference' => 'private',
        ]),
    ]);

    expect((new CorporateSubmittedResponseProjector)->project($item))->toBe([
        'passenger_count' => 3,
        'flight_number' => 'UL 101',
        'notes' => 'Meet at reception',
    ]);
});

it('does not return raw metadata from the corporate booking detail projection', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'CorporateBookingService.php');
    $detailProjection = Str::between($source, 'public function getCorporateBookingDetails(', 'private function applyBookingFilters(');

    expect($detailProjection)
        ->toContain("'submitted_responses' => \$this->submittedResponseProjector->project(\$item)")
        ->toContain("'raw_tracking' => false")
        ->not->toContain("'form_responses'")
        ->not->toContain("'metadata' =>");
});
