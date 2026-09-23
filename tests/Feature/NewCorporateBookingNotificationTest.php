<?php

use App\Models\Booking\Booking;
use App\Models\Corporate\Corporate;
use App\Notifications\NewCorporateBookingNotification;

it('directs the internal team to the booking list with the corporate reference', function () {
    config()->set('app.portal_url', 'https://portal.example.test');

    $booking = new Booking();
    $booking->forceFill([
        'id' => '10000000-0000-4000-8000-000000000001',
        'booking_number' => 'CB-100',
        'corporate_account_id' => '20000000-0000-4000-8000-000000000001',
    ]);
    $corporate = new Corporate();
    $corporate->forceFill(['name' => 'Example Corporate']);
    $booking->setRelation('corporateAccount', $corporate);

    $notification = new NewCorporateBookingNotification($booking);
    $notifiable = (object) ['email' => 'operations@example.test'];
    $data = $notification->toArray($notifiable);
    $mail = $notification->toMail($notifiable);

    expect($notification->via($notifiable))->toBe(['database', 'mail'])
        ->and($data['type'])->toBe('corporate_booking_received')
        ->and($data['data']['booking_number'])->toBe('CB-100')
        ->and($data['url'])->toBe('/bookings/list')
        ->and($mail->actionUrl)->toBe('https://portal.example.test/bookings/list');
});
