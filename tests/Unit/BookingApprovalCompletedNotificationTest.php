<?php

use App\Models\Booking\Booking;
use App\Notifications\BookingApprovalCompleted;
use App\Notifications\CorporatePortalBookingNotification;

it('announces the approver to staff and omits the staff name from the corporate update', function () {
    $booking = new Booking();
    $booking->forceFill([
        'id' => '10000000-0000-4000-8000-000000000001',
        'booking_number' => 'BK-TEST',
    ]);

    $staffData = (new BookingApprovalCompleted($booking, 'Saifullah Haris'))->toArray((object) []);
    $corporateData = (new CorporatePortalBookingNotification($booking, 'approved'))->toArray((object) []);

    expect($staffData['type'])->toBe('booking_approval_completed')
        ->and($staffData['message'])->toContain('Saifullah Haris')
        ->and($corporateData['type'])->toBe('corporate_booking_approved')
        ->and(json_encode($corporateData))->not->toContain('Saifullah Haris');
});
