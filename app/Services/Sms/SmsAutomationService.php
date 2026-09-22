<?php

namespace App\Services\Sms;

use App\Enums\Sms\TransactionalSmsEvent;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingDispatch;
use App\Models\DriverAssignment;
use App\Models\Inquiry;
use App\Models\Sms\SmsMessage;
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

    public function queueBookingConfirmation(Booking $booking, ?bool $sendCustomerSms = null): void
    {
        $booking = $this->resolveBooking($booking);
        $sendCustomerSms = $sendCustomerSms ?? filter_var($booking->notification_sms ?? true, FILTER_VALIDATE_BOOL);
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

    public function previewAdminBookingSummary(?Booking $booking, ?string $template = null): array
    {
        $settings = $this->settingsService->getSettings();
        $variables = $booking
            ? $this->bookingVariables($this->resolveBooking($booking))
            : [
                'booking_number' => 'BK-EXAMPLE-001',
                'customer_name' => 'Example Customer',
                'customer_mobile' => '0771234567',
                'pickup_datetime' => now()->addDay()->setTime(9, 30)->format('d/m/Y h:i A'),
                'pickup_date' => now()->addDay()->format('d/m/Y'),
                'pickup_time' => '09:30 AM',
                'dropoff_datetime' => now()->addDay()->setTime(11, 30)->format('d/m/Y h:i A'),
                'dropoff_date' => now()->addDay()->format('d/m/Y'),
                'dropoff_time' => '11:30 AM',
                'origin' => 'Colombo Fort',
                'destination' => 'Bandaranaike International Airport',
                'item_count' => '1',
                'currency' => 'LKR',
                'total' => '8,500.00',
            ];

        return array_merge([
            'message' => $this->render($template ?: $settings['admin_booking_summary_template'], $variables),
            'variables' => $variables,
            'source' => $booking ? 'booking' : 'example',
            'booking_id' => $booking?->getKey(),
        ], $this->previewEstimate($this->render($template ?: $settings['admin_booking_summary_template'], $variables), $settings));
    }

    public function previewTransactionalTemplate(string $eventKey, string $template, ?Booking $booking = null): array
    {
        $settings = $this->settingsService->getSettings();
        $variables = [
            'booking_number' => 'BK-EXAMPLE-001',
            'inquiry_number' => 'INQ-EXAMPLE-001',
            'customer_name' => 'Example Customer',
            'customer_mobile' => '0771234567',
            'pickup_datetime' => now()->addDay()->setTime(9, 30)->format('d/m/Y h:i A'),
            'pickup_date' => now()->addDay()->format('d/m/Y'),
            'pickup_time' => '09:30 AM',
            'dropoff_datetime' => now()->addDay()->setTime(11, 30)->format('d/m/Y h:i A'),
            'dropoff_date' => now()->addDay()->format('d/m/Y'),
            'dropoff_time' => '11:30 AM',
            'origin' => 'Colombo Fort',
            'destination' => 'Bandaranaike International Airport',
            'item_count' => '1',
            'currency' => 'LKR',
            'total' => '8,500.00',
            'inquiry_type' => 'General inquiry',
            'driver_name' => 'Example Driver',
            'driver_mobile' => '0777654321',
            'vehicle_description' => 'Toyota Prius',
            'vehicle_number' => 'WP CAB-1234',
            'amount' => '8,500.00',
            'payment_reference' => 'PAY-EXAMPLE-001',
        ];

        if ($booking) {
            $variables = array_merge($variables, $this->bookingVariables($this->resolveBooking($booking)));
        }

        $message = $this->render($template, $variables);

        return array_merge([
            'event_key' => $eventKey,
            'message' => $message,
            'variables' => $variables,
            'source' => $booking ? 'booking' : 'example',
            'booking_id' => $booking?->getKey(),
        ], $this->previewEstimate($message, $settings));
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
            ? BookingDispatch::query()->with(['driver.user', 'vehicle.group.make', 'vehicle.group.model'])->findOrFail($dispatch->getKey())
            : tap($dispatch)->loadMissing(['driver.user', 'vehicle.group.make', 'vehicle.group.model']);
        $phone = $booking->customer?->phone;
        if (!$phone || !$dispatch->driver || !$dispatch->vehicle) {
            $this->recordBookingDecision($booking, TransactionalSmsEvent::DriverDispatched, 'missing_data', 'Recipient, driver, or vehicle data is missing.', $dispatch->booking_item_id);
            return;
        }

        $variables = array_merge($this->bookingVariables($booking, $dispatch->booking_item_id), $this->driverVehicleVariables(
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
            ? DriverAssignment::query()->with(['driver.user', 'bookingItem.vehicle.group.make', 'bookingItem.vehicle.group.model'])->findOrFail($assignment->getKey())
            : tap($assignment)->loadMissing(['driver.user', 'bookingItem.vehicle.group.make', 'bookingItem.vehicle.group.model']);
        $phone = $booking->customer?->phone;
        $vehicle = $assignment->bookingItem?->vehicle;
        if (!$phone || !$assignment->driver || !$vehicle) {
            $this->recordBookingDecision($booking, TransactionalSmsEvent::DriverArrived, 'missing_data', 'Recipient, driver, or vehicle data is missing.', $assignment->booking_item_id, $assignment->id);
            return;
        }

        $variables = array_merge($this->bookingVariables($booking, $assignment->booking_item_id), $this->driverVehicleVariables(
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

    public function queueDriverAssignmentFallback(
        Booking $booking,
        DriverAssignment $assignment,
        string $driverPhone
    ): ?SmsMessage {
        $booking = $this->resolveBooking($booking);
        $settings = $this->settingsService->getSettings();
        if (!$settings['enabled'] || empty($settings['driver_assignment_fallback_enabled'])) {
            $this->recordBookingDecision($booking, TransactionalSmsEvent::DriverAssignmentFallback, 'disabled', 'Driver assignment fallback SMS is disabled.', $assignment->booking_item_id, $assignment->id);
            return null;
        }

        $normalizedRecipient = preg_replace('/\D+/', '', $driverPhone);
        if ($normalizedRecipient === '') {
            $this->recordBookingDecision($booking, TransactionalSmsEvent::DriverAssignmentFallback, 'missing_recipient', 'Driver has no SMS recipient number.', $assignment->booking_item_id, $assignment->id);
            return null;
        }

        $message = $this->smsService->queueSingleMessage([
            'recipient' => $driverPhone,
            'message' => $this->render(
                $settings['driver_assignment_fallback_template'],
                $this->bookingVariables($booking, $assignment->booking_item_id)
            ),
            'channel' => 'transactional',
            'source' => 'fallback',
            'context_type' => 'driver_assignment',
            'context_id' => $assignment->id,
            'booking_id' => $booking->id,
            'booking_item_id' => $assignment->booking_item_id,
            'driver_assignment_id' => $assignment->id,
            'template_key' => TransactionalSmsEvent::DriverAssignmentFallback->value,
            'event_key' => TransactionalSmsEvent::DriverAssignmentFallback->value,
            'idempotency_key' => hash('sha256', implode('|', ['driver-fallback', $assignment->id, $assignment->driver_id, $normalizedRecipient])),
            'triggered_at' => now(),
            'meta' => ['audience' => 'driver', 'driver_id' => $assignment->driver_id],
        ]);
        $this->activityService?->recordMessage($message, $message->status === 'dry_run' ? 'dry_run' : ($message->wasRecentlyCreated ? 'queued' : 'duplicate'));

        return $message;
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
            $this->render(
                $settings['trip_completion_template'],
                $this->bookingVariables($booking, $scope === 'item' ? $assignment->booking_item_id : null)
            ),
            $assignment->trip_completed_at ?? $booking->completed_at ?? now(),
            ['audience' => 'customer', 'completion_scope' => $scope],
            $scope === 'item' ? $assignment->booking_item_id : null,
            $scope === 'item' ? $assignment->id : null,
            $scope === 'item' ? (string) $assignment->id : (string) $booking->id
        );
    }

    /**
     * Queue the completion notification from an admin/lifecycle completion.
     * Driver completion already owns its assignment, while portal completion
     * only has the booking and optional selected booking item.
     */
    public function queueTripEnd(Booking $booking, ?string $bookingItemId = null): void
    {
        $assignment = DriverAssignment::query()
            ->where('booking_id', $booking->getKey())
            ->when($bookingItemId, fn ($query) => $query->where('booking_item_id', $bookingItemId))
            ->where('trip_phase', 'completed')
            ->orderByDesc('trip_completed_at')
            ->orderByDesc('updated_at')
            ->first();

        if (!$assignment) {
            $this->recordBookingDecision(
                $this->resolveBooking($booking),
                TransactionalSmsEvent::TripCompleted,
                'missing_assignment',
                'Trip completion was recorded without a completed driver assignment.',
                $bookingItemId
            );
            return;
        }

        $this->queueTripCompleted($booking, $assignment);
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

    private function bookingVariables(Booking $booking, ?string $bookingItemId = null): array
    {
        $items = $booking->bookingItems;
        $firstItem = $bookingItemId
            ? ($items->firstWhere('id', $bookingItemId) ?? $items->first())
            : $items->first();

        $pickupDate = $this->formatBookingDate($firstItem?->from_date);
        $pickupTime = $this->formatBookingTime($firstItem?->from_time);
        $dropoffDate = $this->formatBookingDate($firstItem?->to_date);
        $dropoffTime = $this->formatBookingTime($firstItem?->to_time);

        return [
            'booking_number' => (string) ($booking->booking_number ?: $booking->id),
            'customer_name' => (string) ($booking->customer?->full_name ?: 'Customer'),
            'customer_mobile' => (string) ($booking->customer?->phone ?: '-'),
            // Booking dates and times are persisted in separate columns. Never infer the
            // customer-facing time from the date column's midnight/timezone component.
            'pickup_datetime' => $this->formatBookingDateTime($pickupDate, $pickupTime),
            'pickup_date' => $pickupDate,
            'pickup_time' => $pickupTime,
            'dropoff_datetime' => $this->formatBookingDateTime($dropoffDate, $dropoffTime),
            'dropoff_date' => $dropoffDate,
            'dropoff_time' => $dropoffTime,
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

    private function formatBookingDate(mixed $date): string
    {
        if (!$date) {
            return 'To be confirmed';
        }

        try {
            return Carbon::parse($date)->format('d/m/Y');
        } catch (\Throwable) {
            return 'To be confirmed';
        }
    }

    private function formatBookingTime(mixed $time): string
    {
        $value = trim((string) $time);
        if ($value === '') {
            return 'To be confirmed';
        }

        foreach (['H:i:s', 'H:i'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);
                if ($parsed !== false && $parsed->format($format) === $value) {
                    return $parsed->format('h:i A');
                }
            } catch (\Throwable) {
                // Try the next supported database time representation.
            }
        }

        return 'To be confirmed';
    }

    private function formatBookingDateTime(string $date, string $time): string
    {
        if ($date === 'To be confirmed') {
            return $date;
        }

        return $time === 'To be confirmed' ? $date . ' (time to be confirmed)' : $date . ' ' . $time;
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
        $make = trim((string) ($vehicle->group?->make?->name ?? ''));
        $model = trim((string) ($vehicle->group?->model?->name ?? ''));

        return [
            'driver_name' => $driverName !== '' ? $driverName : 'Driver',
            'driver_mobile' => (string) ($driver->phone ?? $driver->user?->phone ?? '-'),
            'vehicle_description' => trim($make . ' ' . $model) ?: 'Assigned vehicle',
            'vehicle_number' => (string) ($vehicle->license_plate ?? '-'),
        ];
    }

    private function render(string $template, array $variables): string
    {
        if (str_contains($template, '{company_')) {
            $websiteSettings = app(\App\Services\WebsiteSettingsService::class);
            $variables += [
                'company_name' => $websiteSettings->get('company_name', config('app.name')),
                'company_phone' => $websiteSettings->get('company_phone', ''),
                'company_website' => $websiteSettings->get('company_website', config('app.url')),
            ];
        }
        $replacements = [];
        foreach ($variables as $key => $value) {
            $replacements['{' . $key . '}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }

    private function previewEstimate(string $message, array $settings): array
    {
        $facts = (new SmsSegmentCalculator())->calculate($message);
        $unitCost = (float) ($settings['cost_per_segment'] ?? 0);

        return array_merge($facts, [
            'unit_cost' => $unitCost,
            'estimated_cost' => round($facts['segments'] * $unitCost, 4),
            'cost_currency' => (string) ($settings['cost_currency'] ?? 'LKR'),
        ]);
    }
}
