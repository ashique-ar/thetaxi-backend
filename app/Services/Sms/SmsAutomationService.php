<?php

namespace App\Services\Sms;

use App\Enums\Sms\TransactionalSmsEvent;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingDispatch;
use App\Models\DriverAssignment;
use App\Models\Inquiry;
use Illuminate\Support\Carbon;

class SmsAutomationService
{
    public function __construct(
        private SmsSettingsService $settingsService,
        private SmsService $smsService,
        private ?BookingCommunicationActivityService $activityService = null
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

    public function queueBookingConfirmation(Booking $booking, bool $sendCustomerSms = true): void
    {
        $booking = $this->resolveBooking($booking);
        $settings = $this->settingsService->getSettings();
        if (!$settings['enabled']) {
            $this->recordBookingDecision($booking, TransactionalSmsEvent::BookingConfirmed, 'disabled', 'Global SMS setting is disabled.');
            return;
        }

        $variables = $this->bookingVariables($booking);
        $triggeredAt = $booking->confirmed_at ?? now();

        if ($sendCustomerSms && !empty($settings['booking_confirmation_enabled'])) {
            $phone = $booking->customer?->phone;
            if ($phone) {
                $this->queueBookingEvent(
                    $booking,
                    TransactionalSmsEvent::BookingConfirmed->value,
                    $phone,
                    $this->render($settings['booking_confirmation_template'], $variables),
                    $triggeredAt,
                    ['audience' => 'customer']
                );
            } else {
                $this->recordBookingDecision($booking, TransactionalSmsEvent::BookingConfirmed, 'missing_recipient', 'Customer has no SMS recipient number.');
            }
        } else {
            $this->recordBookingDecision($booking, TransactionalSmsEvent::BookingConfirmed, 'disabled', $sendCustomerSms ? 'Booking confirmation event is disabled.' : 'Internal user did not request customer SMS.');
        }

        if (empty($settings['admin_booking_summary_enabled'])) {
            return;
        }

        foreach ($settings['admin_booking_summary_numbers'] as $adminNumber) {
            $this->queueBookingEvent(
                $booking,
                TransactionalSmsEvent::AdminBookingConfirmedSummary->value,
                $adminNumber,
                $this->render($settings['admin_booking_summary_template'], $variables),
                $triggeredAt,
                ['audience' => 'admin_summary']
            );
        }
    }

    public function queueWebsiteQuotationRequested(Booking $booking): void
    {
        $booking = $this->resolveBooking($booking);
        $settings = $this->settingsService->getSettings();
        if (!$settings['enabled'] || empty($settings['quotation_requested_enabled'])) {
            $this->recordBookingDecision($booking, TransactionalSmsEvent::WebsiteQuotationRequested, 'disabled', 'Website quotation SMS is disabled.');
            return;
        }

        $phone = $booking->customer?->phone;
        if (!$phone) {
            $this->recordBookingDecision($booking, TransactionalSmsEvent::WebsiteQuotationRequested, 'missing_recipient', 'Customer has no SMS recipient number.');
            return;
        }

        $this->queueBookingEvent(
            $booking,
            TransactionalSmsEvent::WebsiteQuotationRequested->value,
            $phone,
            $this->render($settings['quotation_requested_template'], $this->bookingVariables($booking)),
            $booking->updated_at ?? now(),
            ['audience' => 'customer', 'source' => 'website']
        );
    }

    public function queueWebsiteInquiryReceived(Inquiry $inquiry): void
    {
        $settings = $this->settingsService->getSettings();
        if (!$settings['enabled'] || empty($settings['inquiry_received_enabled'])) {
            $this->recordInquiryDecision($inquiry, 'disabled', 'Website inquiry SMS is disabled.');
            return;
        }
        if (!$inquiry->phone) {
            $this->recordInquiryDecision($inquiry, 'missing_recipient', 'Inquiry has no SMS recipient number.');
            return;
        }

        $inquiry = $inquiry->exists
            ? Inquiry::query()->findOrFail($inquiry->getKey())
            : $inquiry;
        $recipient = (string) $inquiry->phone;
        $normalizedRecipient = preg_replace('/\D+/', '', $recipient);
        if (str_starts_with($normalizedRecipient, '0')) {
            $normalizedRecipient = '94' . substr($normalizedRecipient, 1);
        }

        $message = $this->render($settings['inquiry_received_template'], [
            'inquiry_number' => (string) ($inquiry->inquiry_number ?: $inquiry->id),
            'customer_name' => (string) ($inquiry->name ?: 'Customer'),
            'inquiry_type' => (string) ($inquiry->inquiry_type ?: 'general'),
        ]);

        $messageRecord = $this->smsService->queueSingleMessage([
            'recipient' => $recipient,
            'message' => $message,
            'channel' => 'transactional',
            'source' => 'automation',
            'context_type' => 'inquiry',
            'context_id' => $inquiry->id,
            'inquiry_id' => $inquiry->id,
            'template_key' => TransactionalSmsEvent::WebsiteInquiryReceived->value,
            'event_key' => TransactionalSmsEvent::WebsiteInquiryReceived->value,
            'idempotency_key' => hash('sha256', implode('|', [
                'inquiry', $inquiry->id, TransactionalSmsEvent::WebsiteInquiryReceived->value, $normalizedRecipient,
            ])),
            'triggered_at' => $inquiry->created_at ?? now(),
            'meta' => [
                'inquiry_id' => $inquiry->id,
                'inquiry_number' => $inquiry->inquiry_number,
                'audience' => 'customer',
                'source' => 'website',
            ],
        ]);
        $this->activityService?->recordMessage(
            $messageRecord,
            $messageRecord->status === 'dry_run'
                ? 'dry_run'
                : ($messageRecord->wasRecentlyCreated ? 'queued' : 'duplicate')
        );
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

    public function queueDriverDispatched(Booking $booking, BookingDispatch $dispatch): void
    {
        $booking = $this->resolveBooking($booking);
        $settings = $this->settingsService->getSettings();
        if (!$settings['enabled'] || empty($settings['driver_dispatched_enabled'])) {
            $this->recordBookingDecision($booking, TransactionalSmsEvent::DriverDispatched, 'disabled', 'Driver-dispatched SMS is disabled.');
            return;
        }

        $dispatch = $dispatch->exists
            ? BookingDispatch::query()->with(['driver.user', 'vehicle.make', 'vehicle.model'])->findOrFail($dispatch->getKey())
            : tap($dispatch)->loadMissing(['driver.user', 'vehicle.make', 'vehicle.model']);
        $phone = $booking->customer?->phone;
        if (!$phone || !$dispatch->driver || !$dispatch->vehicle) {
            $this->recordBookingDecision($booking, TransactionalSmsEvent::DriverDispatched, 'missing_data', 'Recipient, driver, or vehicle data is missing.', $dispatch->booking_item_id);
            return;
        }

        $variables = array_merge($this->bookingVariables($booking), $this->driverVehicleVariables(
            $dispatch->driver,
            $dispatch->vehicle
        ));

        $this->queueBookingEvent(
            $booking,
            TransactionalSmsEvent::DriverDispatched->value,
            $phone,
            $this->render($settings['driver_dispatched_template'], $variables),
            $dispatch->dispatched_at ?? now(),
            ['audience' => 'customer', 'dispatch_id' => $dispatch->id],
            $dispatch->booking_item_id,
            null,
            (string) ($dispatch->booking_item_id ?: $dispatch->id)
        );
    }

    public function queueDriverArrived(Booking $booking, DriverAssignment $assignment): void
    {
        $booking = $this->resolveBooking($booking);
        $settings = $this->settingsService->getSettings();
        if (!$settings['enabled'] || empty($settings['driver_arrived_enabled'])) {
            $this->recordBookingDecision($booking, TransactionalSmsEvent::DriverArrived, 'disabled', 'Driver-arrived SMS is disabled.');
            return;
        }

        $assignment = $assignment->exists
            ? DriverAssignment::query()->with(['driver.user', 'bookingItem.vehicle.make', 'bookingItem.vehicle.model'])->findOrFail($assignment->getKey())
            : tap($assignment)->loadMissing(['driver.user', 'bookingItem.vehicle.make', 'bookingItem.vehicle.model']);
        $phone = $booking->customer?->phone;
        $vehicle = $assignment->bookingItem?->vehicle;
        if (!$phone || !$assignment->driver || !$vehicle) {
            $this->recordBookingDecision($booking, TransactionalSmsEvent::DriverArrived, 'missing_data', 'Recipient, driver, or vehicle data is missing.', $assignment->booking_item_id, $assignment->id);
            return;
        }

        $variables = array_merge($this->bookingVariables($booking), $this->driverVehicleVariables(
            $assignment->driver,
            $vehicle
        ));

        $this->queueBookingEvent(
            $booking,
            TransactionalSmsEvent::DriverArrived->value,
            $phone,
            $this->render($settings['driver_arrived_template'], $variables),
            $assignment->pickup_arrived_at ?? now(),
            ['audience' => 'customer'],
            $assignment->booking_item_id,
            $assignment->id,
            (string) $assignment->id
        );
    }

    public function recordTripStarted(Booking $booking, DriverAssignment $assignment): void
    {
        if (!$this->activityService) {
            return;
        }

        $this->activityService->record([
            'booking_id' => $booking->id,
            'booking_item_id' => $assignment->booking_item_id,
            'driver_assignment_id' => $assignment->id,
            'event_key' => TransactionalSmsEvent::TripStarted->value,
            'channel' => 'timeline',
            'result_status' => 'recorded',
            'title' => 'Trip started',
            'detail' => 'Trip start recorded without customer SMS.',
            'event_at' => $assignment->trip_started_at ?? now(),
            'idempotency_key' => hash('sha256', 'trip-started|' . $assignment->id),
        ]);
    }

    public function queueTripCompleted(Booking $booking, DriverAssignment $assignment): void
    {
        $booking = $this->resolveBooking($booking);
        $settings = $this->settingsService->getSettings();
        $scope = $settings['trip_completion_scope'] ?? 'booking';

        if ($scope === 'booking' && (string) $booking->status !== 'completed') {
            $this->recordBookingDecision($booking, TransactionalSmsEvent::TripCompleted, 'waiting', 'Completion SMS is owned by the aggregate booking and remaining items are not complete.', $assignment->booking_item_id, $assignment->id);
            return;
        }

        if (!$settings['enabled'] || empty($settings['trip_completion_enabled'])) {
            $this->recordBookingDecision($booking, TransactionalSmsEvent::TripCompleted, 'disabled', 'Optional trip-completion SMS is disabled.', $assignment->booking_item_id, $assignment->id);
            return;
        }

        $phone = $booking->customer?->phone ?? null;
        if (!$phone) {
            $this->recordBookingDecision($booking, TransactionalSmsEvent::TripCompleted, 'missing_recipient', 'Customer has no SMS recipient number.', $assignment->booking_item_id, $assignment->id);
            return;
        }

        $this->queueBookingEvent(
            $booking,
            TransactionalSmsEvent::TripCompleted->value,
            $phone,
            $this->render($settings['trip_completion_template'], $this->bookingVariables($booking)),
            $assignment->trip_completed_at ?? $booking->completed_at ?? now(),
            ['audience' => 'customer', 'completion_scope' => $scope],
            $scope === 'item' ? $assignment->booking_item_id : null,
            $scope === 'item' ? $assignment->id : null,
            $scope === 'item' ? (string) $assignment->id : (string) $booking->id
        );
    }

    public function queuePaymentConfirmation(
        Booking $booking,
        float $amount,
        string $currency = 'LKR',
        ?string $paymentReference = null
    ): void
    {
        $booking = $this->resolveBooking($booking);
        $settings = $this->settingsService->getSettings();
        if (!$settings['enabled'] || empty($settings['payment_confirmation_enabled'])) {
            $this->recordBookingDecision($booking, TransactionalSmsEvent::PaymentReceived, 'disabled', 'Payment confirmation SMS is disabled.');
            return;
        }

        $phone = $booking->customer?->phone ?? null;
        if (!$phone) {
            $this->recordBookingDecision($booking, TransactionalSmsEvent::PaymentReceived, 'missing_recipient', 'Customer has no SMS recipient number.');
            return;
        }

        $reference = trim((string) ($paymentReference ?: $booking->payment_reference ?: 'payment'));
        $variables = array_merge($this->bookingVariables($booking), [
            'amount' => number_format($amount, 2, '.', ','),
            'currency' => $currency,
            'payment_reference' => $reference,
        ]);

        $this->queueBookingEvent(
            $booking,
            TransactionalSmsEvent::PaymentReceived->value,
            $phone,
            $this->render($settings['payment_confirmation_template'], $variables),
            now(),
            ['audience' => 'customer', 'amount' => $amount, 'currency' => $currency, 'payment_reference' => $reference],
            null,
            null,
            $reference
        );
    }

    private function queueBookingEvent(
        Booking $booking,
        string $eventKey,
        string $recipient,
        string $message,
        mixed $triggeredAt,
        array $meta = [],
        ?string $bookingItemId = null,
        ?string $driverAssignmentId = null,
        ?string $scopeId = null
    ): void {
        $normalizedRecipient = preg_replace('/\D+/', '', $recipient);
        if (str_starts_with($normalizedRecipient, '0')) {
            $normalizedRecipient = '94' . substr($normalizedRecipient, 1);
        }

        $messageRecord = $this->smsService->queueSingleMessage([
            'recipient' => $recipient,
            'message' => $message,
            'channel' => 'transactional',
            'source' => 'automation',
            'context_type' => 'booking',
            'context_id' => $booking->id,
            'booking_id' => $booking->id,
            'booking_item_id' => $bookingItemId,
            'driver_assignment_id' => $driverAssignmentId,
            'template_key' => $eventKey,
            'event_key' => $eventKey,
            'idempotency_key' => hash('sha256', implode('|', [
                'booking',
                $booking->id,
                $scopeId ?: $booking->id,
                $eventKey,
                $normalizedRecipient,
            ])),
            'triggered_at' => $triggeredAt,
            'meta' => array_merge($meta, [
                'booking_id' => $booking->id,
                'booking_number' => $booking->booking_number,
            ]),
        ]);

        $this->activityService?->recordMessage(
            $messageRecord,
            $messageRecord->status === 'dry_run'
                ? 'dry_run'
                : ($messageRecord->wasRecentlyCreated ? 'queued' : 'duplicate')
        );
    }

    private function resolveBooking(Booking $booking): Booking
    {
        if ($booking->exists) {
            return Booking::query()
                ->with(['customer.user', 'bookingItems'])
                ->findOrFail($booking->getKey());
        }

        return tap($booking)->loadMissing(['customer.user', 'bookingItems']);
    }

    private function recordBookingDecision(
        Booking $booking,
        TransactionalSmsEvent $event,
        string $status,
        string $detail,
        ?string $bookingItemId = null,
        ?string $assignmentId = null
    ): void {
        if (!$this->activityService || !$booking->getKey()) {
            return;
        }

        $this->activityService->record([
            'booking_id' => $booking->getKey(),
            'booking_item_id' => $bookingItemId,
            'driver_assignment_id' => $assignmentId,
            'event_key' => $event->value,
            'result_status' => $status,
            'title' => 'Transactional SMS ' . str_replace('_', ' ', $status),
            'detail' => $detail,
            'idempotency_key' => hash('sha256', implode('|', ['booking-decision', $booking->getKey(), $bookingItemId ?: $booking->getKey(), $event->value, $status])),
        ]);
    }

    private function recordInquiryDecision(Inquiry $inquiry, string $status, string $detail): void
    {
        if (!$this->activityService || !$inquiry->getKey()) {
            return;
        }

        $this->activityService->record([
            'inquiry_id' => $inquiry->getKey(),
            'event_key' => TransactionalSmsEvent::WebsiteInquiryReceived->value,
            'result_status' => $status,
            'title' => 'Transactional SMS ' . str_replace('_', ' ', $status),
            'detail' => $detail,
            'idempotency_key' => hash('sha256', implode('|', ['inquiry-decision', $inquiry->getKey(), TransactionalSmsEvent::WebsiteInquiryReceived->value, $status])),
        ]);
    }

    private function bookingVariables(Booking $booking): array
    {
        $items = $booking->bookingItems;
        $firstItem = $items->first();
        $pickup = $firstItem?->from_date ? Carbon::parse($firstItem->from_date) : null;

        return [
            'booking_number' => (string) ($booking->booking_number ?: $booking->id),
            'customer_name' => (string) ($booking->customer?->full_name ?: 'Customer'),
            'customer_mobile' => (string) ($booking->customer?->phone ?: '-'),
            'pickup_datetime' => $pickup?->format('d/m/Y h:i A') ?? 'To be confirmed',
            'origin' => $this->locationLabel($firstItem?->pickup_location),
            'destination' => $this->locationLabel($firstItem?->dropoff_location),
            'item_count' => (string) max(1, $items->count()),
            'currency' => (string) ($booking->currency ?: $firstItem?->currency ?: 'LKR'),
            'total' => number_format((float) (
                $booking->total_actual
                ?? $booking->total_estimated
                ?? $booking->amount_to_pay
                ?? $items->sum('total_price')
            ), 2, '.', ','),
        ];
    }

    private function locationLabel(mixed $location): string
    {
        if (is_string($location)) {
            $decoded = json_decode($location, true);
            return is_array($decoded) ? $this->locationLabel($decoded) : trim($location);
        }

        if (!is_array($location)) {
            return 'Not specified';
        }

        return trim((string) ($location['address'] ?? $location['name'] ?? $location['label'] ?? 'Not specified'));
    }

    private function driverVehicleVariables(mixed $driver, mixed $vehicle): array
    {
        $userName = trim((string) ($driver->user?->first_name ?? '') . ' ' . (string) ($driver->user?->last_name ?? ''));
        $driverName = trim((string) ($driver->full_name ?? $driver->name ?? $userName));
        $make = trim((string) ($vehicle->make?->name ?? ''));
        $model = trim((string) ($vehicle->model?->name ?? ''));

        return [
            'driver_name' => $driverName !== '' ? $driverName : 'Driver',
            'driver_mobile' => (string) ($driver->phone ?? $driver->user?->phone ?? '-'),
            'vehicle_description' => trim($make . ' ' . $model) ?: 'Assigned vehicle',
            'vehicle_number' => (string) ($vehicle->license_plate ?? '-'),
        ];
    }

    private function render(string $template, array $variables): string
    {
        $replacements = [];
        foreach ($variables as $key => $value) {
            $replacements['{' . $key . '}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }
}
