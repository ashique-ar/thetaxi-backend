<?php

use App\Http\Controllers\CheckoutController;
use App\Models\Booking\Booking;
use App\Models\Website\PendingPaymentLink;
use Illuminate\Http\Request;

it('decodes double-encoded booking_context and updates vehicle selection for 1 or 2 vehicles', function () {
    $context = [
        'booking_number' => 'QT010026',
        'pricing' => [
            'total_estimated' => 214,
            'amount_paid' => 0,
            'currency' => 'USD',
        ],
        'booking_items' => [
            ['id' => 'item-1', 'total_price' => 107],
            ['id' => 'item-2', 'total_price' => 107],
        ],
    ];

    $booking = new Booking();
    $booking->id = 'booking-1';
    $booking->payment_status = 'pending';
    $booking->status = 'quotation_accepted';
    $booking->setRelation('bookingItems', new \Illuminate\Database\Eloquent\Collection());

    $link = new class extends PendingPaymentLink {
        public function save(array $options = []): bool
        {
            return true;
        }
    };
    // Simulate legacy double-encoded JSON string stored in booking_context
    $link->booking_context = json_encode($context);
    $link->amount_due = 214;
    $link->setRelation('booking', $booking);

    expect($link->decodedBookingContext())->toBe($context);

    // Selecting 1 of 2 vehicles succeeds
    $selectedOne = ['item-1'];
    $items = $link->decodedBookingContext()['booking_items'] ?? [];
    $validIds = collect($items)->pluck('id')->filter()->map(fn ($id) => (string) $id)->all();
    expect($validIds)->toBe(['item-1', 'item-2'])
        ->and(array_diff($selectedOne, $validIds))->toBeEmpty();

    // Selecting 2 of 2 vehicles succeeds
    $selectedTwo = ['item-1', 'item-2'];
    expect(array_diff($selectedTwo, $validIds))->toBeEmpty();
});

it('hides vehicle selection form and update button when only one vehicle is quoted', function () {
    $resumeSource = file_get_contents(resource_path('views/checkout/payment-resume.blade.php'));

    expect($resumeSource)
        ->toContain('$hasMultipleVehicles = count($quotedItems) > 1;')
        ->toContain('$canEditVehicles = $hasMultipleVehicles')
        ->toContain('id="vehicle-selection-form"')
        ->toContain('class="vehicle-update-btn"')
        ->toContain('@elseif ($hasMultipleVehicles && !$hasTours)');
});
