<?php

namespace App\Services\Sms;

use App\Models\Booking\Booking;

class SmsAutomationService
{
    public function __construct(
        private SmsSettingsService $settingsService,
        private SmsService $smsService
    ) {}

    public function queueBookingStatusUpdate(Booking $booking, string $status, ?string $recipient = null): void
    {
        if (!$this->settingsService->getSettings()['booking_status_enabled']) {
            return;
        }

        $recipientNumber = $recipient ?: $booking->customer?->phone ?? null;
        if (!$recipientNumber) {
            return;
        }

        $message = sprintf(
            'Booking %s status updated to %s.',
            $booking->booking_number ?: $booking->id,
            str_replace('_', ' ', $status)
        );

        $this->smsService->queueSingleMessage([
            'recipient'    => $recipientNumber,
            'message'      => $message,
            'channel'      => 'transactional',
            'context_type' => 'booking',
            'context_id'   => $booking->id,
            'template_key' => 'booking_status.' . $status,
            'meta'         => [
                'booking_id'     => $booking->id,
                'booking_number' => $booking->booking_number,
                'status'         => $status,
            ],
        ]);
    }

    public function queueBookingConfirmation(Booking $booking): void
    {
        $settings = $this->settingsService->getSettings();
        if (empty($settings['booking_confirmation_enabled'])) {
            return;
        }

        $phone = $booking->customer?->phone ?? null;
        if (!$phone) {
            return;
        }

        $this->smsService->queueSingleMessage([
            'recipient'    => $phone,
            'message'      => sprintf(
                'Your booking #%s is confirmed. Pickup: %s at %s.',
                $booking->booking_number ?: $booking->id,
                $booking->from_date ? \Carbon\Carbon::parse($booking->from_date)->format('d M Y') : 'TBC',
                $booking->pickup_location ?? 'TBC'
            ),
            'channel'      => 'transactional',
            'context_type' => 'booking',
            'context_id'   => $booking->id,
            'template_key' => 'booking.confirmed',
            'meta'         => ['booking_id' => $booking->id],
        ]);
    }

    public function queueDriverAssignment(Booking $booking, string $driverName, ?string $vehiclePlate = null): void
    {
        $settings = $this->settingsService->getSettings();
        if (empty($settings['driver_assignment_enabled'])) {
            return;
        }

        $phone = $booking->customer?->phone ?? null;
        if (!$phone) {
            return;
        }

        $vehicle = $vehiclePlate ? " in vehicle {$vehiclePlate}" : '';
        $this->smsService->queueSingleMessage([
            'recipient'    => $phone,
            'message'      => sprintf(
                'Driver %s has been assigned to your booking #%s%s.',
                $driverName,
                $booking->booking_number ?: $booking->id,
                $vehicle
            ),
            'channel'      => 'transactional',
            'context_type' => 'booking',
            'context_id'   => $booking->id,
            'template_key' => 'driver.assigned',
            'meta'         => ['booking_id' => $booking->id, 'driver_name' => $driverName],
        ]);
    }

    public function queueTripStart(Booking $booking): void
    {
        $settings = $this->settingsService->getSettings();
        if (empty($settings['trip_start_enabled'])) {
            return;
        }

        $phone = $booking->customer?->phone ?? null;
        if (!$phone) {
            return;
        }

        $this->smsService->queueSingleMessage([
            'recipient'    => $phone,
            'message'      => sprintf(
                'Your trip for booking #%s has started.',
                $booking->booking_number ?: $booking->id
            ),
            'channel'      => 'transactional',
            'context_type' => 'booking',
            'context_id'   => $booking->id,
            'template_key' => 'trip.started',
            'meta'         => ['booking_id' => $booking->id],
        ]);
    }

    public function queueTripEnd(Booking $booking): void
    {
        $settings = $this->settingsService->getSettings();
        if (empty($settings['trip_end_enabled'])) {
            return;
        }

        $phone = $booking->customer?->phone ?? null;
        if (!$phone) {
            return;
        }

        $this->smsService->queueSingleMessage([
            'recipient'    => $phone,
            'message'      => sprintf(
                'Your trip for booking #%s has ended. Thank you for choosing us!',
                $booking->booking_number ?: $booking->id
            ),
            'channel'      => 'transactional',
            'context_type' => 'booking',
            'context_id'   => $booking->id,
            'template_key' => 'trip.ended',
            'meta'         => ['booking_id' => $booking->id],
        ]);
    }

    public function queuePaymentConfirmation(Booking $booking, float $amount, string $currency = 'LKR'): void
    {
        $settings = $this->settingsService->getSettings();
        if (empty($settings['payment_confirmation_enabled'])) {
            return;
        }

        $phone = $booking->customer?->phone ?? null;
        if (!$phone) {
            return;
        }

        $this->smsService->queueSingleMessage([
            'recipient'    => $phone,
            'message'      => sprintf(
                'Payment of %s %.2f received for booking #%s. Thank you!',
                $currency,
                $amount,
                $booking->booking_number ?: $booking->id
            ),
            'channel'      => 'transactional',
            'context_type' => 'booking',
            'context_id'   => $booking->id,
            'template_key' => 'payment.confirmed',
            'meta'         => ['booking_id' => $booking->id, 'amount' => $amount, 'currency' => $currency],
        ]);
    }
}
