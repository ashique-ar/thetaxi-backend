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
            'recipient' => $recipientNumber,
            'message' => $message,
            'channel' => 'transactional',
            'context_type' => 'booking',
            'context_id' => $booking->id,
            'template_key' => 'booking_status.' . $status,
            'meta' => [
                'booking_id' => $booking->id,
                'booking_number' => $booking->booking_number,
                'status' => $status,
            ],
        ]);
    }
}
