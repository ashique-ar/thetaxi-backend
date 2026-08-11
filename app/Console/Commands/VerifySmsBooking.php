<?php

namespace App\Console\Commands;

use App\Enums\Sms\TransactionalSmsEvent;
use App\Models\Booking\Booking;
use App\Models\Sms\SmsMessage;
use App\Services\Sms\SmsSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VerifySmsBooking extends Command
{
    protected $signature = 'sms:verify-booking {booking : Booking UUID or booking number} {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only verification of transactional SMS records for one booking';

    public function __construct(private SmsSettingsService $settingsService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $reference = (string) $this->argument('booking');
        $booking = Booking::query()
            ->whereKey($reference)
            ->orWhere('booking_number', $reference)
            ->first();

        if (!$booking) {
            $this->error('Booking not found. No records were changed.');
            return self::FAILURE;
        }

        $messages = SmsMessage::query()->withInactive()
            ->where('booking_id', $booking->id)
            ->orderBy('created_at')
            ->get();
        $settings = $this->settingsService->getSettings();
        $customerEvents = [
            TransactionalSmsEvent::BookingConfirmed->value,
            TransactionalSmsEvent::DriverDispatched->value,
            TransactionalSmsEvent::DriverArrived->value,
        ];
        $adminEvent = TransactionalSmsEvent::AdminBookingConfirmedSummary->value;
        $adminRecipients = $settings['admin_booking_summary_enabled']
            ? ($settings['admin_booking_summary_numbers'] ?? [])
            : [];
        $byEvent = $messages->groupBy('event_key')->map->count()->all();
        $missingCustomerEvents = array_values(array_filter(
            $customerEvents,
            fn (string $event): bool => ($byEvent[$event] ?? 0) !== 1
        ));
        $duplicates = collect($byEvent)->filter(
            fn (int $count, string $event): bool => in_array($event, $customerEvents, true)
                ? $count > 1
                : ($event === $adminEvent && $count > count($adminRecipients))
        )->all();
        $allowedEvents = [...$customerEvents, $adminEvent];
        $unexpected = $messages->whereNotIn('event_key', $allowedEvents)->count();
        $adminLifecycleCopies = $messages
            ->whereIn('event_key', $customerEvents)
            ->filter(fn (SmsMessage $message): bool => in_array($message->normalized_recipient, $adminRecipients, true))
            ->count();
        $adminSummaryCount = (int) ($byEvent[$adminEvent] ?? 0);
        $driverNotifications = $this->driverNotificationCount((string) $booking->id);
        $compliant = $missingCustomerEvents === []
            && $duplicates === []
            && $unexpected === 0
            && $adminLifecycleCopies === 0
            && $adminSummaryCount === count($adminRecipients)
            && $driverNotifications >= 1;

        $report = [
            'read_only' => true,
            'booking' => ['id' => $booking->id, 'booking_number' => $booking->booking_number],
            'compliant' => $compliant,
            'expected' => [
                'customer_events' => $customerEvents,
                'admin_summaries' => count($adminRecipients),
                'driver_notifications_minimum' => 1,
            ],
            'actual' => [
                'messages' => $messages->count(),
                'by_event' => $byEvent,
                'admin_summaries' => $adminSummaryCount,
                'driver_notifications' => $driverNotifications,
            ],
            'issues' => [
                'missing_or_non_unique_customer_events' => $missingCustomerEvents,
                'duplicate_events' => $duplicates,
                'unexpected_messages' => $unexpected,
                'admin_lifecycle_copies' => $adminLifecycleCopies,
                'admin_summary_count_mismatch' => $adminSummaryCount !== count($adminRecipients),
                'driver_notification_missing' => $driverNotifications < 1,
            ],
            'records' => $messages->map(fn (SmsMessage $message): array => [
                'id' => $message->id,
                'event_key' => $message->event_key,
                'status' => $message->status,
                'recipient' => $this->mask((string) ($message->normalized_recipient ?: $message->recipient)),
                'booking_item_id' => $message->booking_item_id,
                'driver_assignment_id' => $message->driver_assignment_id,
                'created_at' => $message->created_at?->toIso8601String(),
            ])->all(),
            'safety' => ['provider_contacted' => false, 'jobs_dispatched' => false, 'records_changed' => false],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("SMS booking verification: {$booking->booking_number}");
            $this->table(['Event', 'Count'], collect($byEvent)->map(fn ($count, $event): array => [$event ?: '(none)', $count])->values()->all());
            $this->table(['Check', 'Result'], [
                ['Customer normal three', $missingCustomerEvents === [] ? 'PASS' : 'FAIL'],
                ['Admin summary count', $adminSummaryCount === count($adminRecipients) ? 'PASS' : 'FAIL'],
                ['No duplicate events', $duplicates === [] ? 'PASS' : 'FAIL'],
                ['No unexpected SMS', $unexpected === 0 ? 'PASS' : 'FAIL'],
                ['No admin lifecycle copies', $adminLifecycleCopies === 0 ? 'PASS' : 'FAIL'],
                ['Driver app notification', $driverNotifications >= 1 ? 'PASS' : 'FAIL'],
            ]);
            $compliant ? $this->info('Booking SMS flow is compliant.') : $this->warn('Booking SMS flow is not compliant; review the reported checks.');
        }

        return $compliant ? self::SUCCESS : self::FAILURE;
    }

    private function driverNotificationCount(string $bookingId): int
    {
        if (!Schema::hasTable('driver_assignment_notifications') || !Schema::hasTable('driver_assignments')) {
            return 0;
        }

        return DB::table('driver_assignment_notifications as notification')
            ->join('driver_assignments as assignment', 'assignment.id', '=', 'notification.assignment_id')
            ->where('assignment.booking_id', $bookingId)
            ->count();
    }

    private function mask(string $recipient): string
    {
        return str_repeat('*', max(0, strlen($recipient) - 4)) . substr($recipient, -4);
    }
}
