<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingCustomerMobileActivity;
use App\Models\Booking\BookingItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CustomerMobileActivityService
{
    /**
     * Persist one immutable, idempotent customer-mobile telemetry event.
     *
     * @return array{activity: BookingCustomerMobileActivity, summary: array<string, mixed>, replayed: bool}
     */
    public function record(
        Booking $booking,
        BookingItem $bookingItem,
        User $user,
        array $payload,
        array $requestContext = []
    ): array {
        $this->assertOwnership($booking, $bookingItem, $user);
        $normalized = $this->normalizePayload($payload);
        $payloadHash = $this->payloadHash($normalized);

        return DB::transaction(function () use (
            $booking,
            $bookingItem,
            $user,
            $normalized,
            $payloadHash,
            $requestContext
        ) {
            // The item lock serializes events for one trip and makes the unique
            // idempotency check race-safe on PostgreSQL and MySQL.
            $lockedItem = BookingItem::query()->lockForUpdate()->findOrFail($bookingItem->id);
            if ((string) $lockedItem->booking_id !== (string) $booking->id) {
                throw new AuthorizationException('The booking item does not belong to this booking.');
            }
            $persistedBooking = Booking::query()->with('customer')->findOrFail($booking->id);
            $this->assertOwnership($persistedBooking, $lockedItem, $user);

            $existing = BookingCustomerMobileActivity::query()
                ->where('booking_item_id', $lockedItem->id)
                ->where('client_event_id', $normalized['client_event_id'])
                ->first();

            if ($existing) {
                if (!hash_equals((string) $existing->payload_hash, $payloadHash)) {
                    throw new \DomainException(
                        'This client_event_id was already used with different telemetry.'
                    );
                }

                return [
                    'activity' => $existing,
                    'summary' => $this->summaryForItem((string) $lockedItem->id),
                    'replayed' => true,
                ];
            }

            $priorSummary = $this->summaryForItem((string) $lockedItem->id);
            $this->assertMonotonicCumulativeMetrics($normalized, $priorSummary);

            $activity = BookingCustomerMobileActivity::create([
                'booking_id' => $persistedBooking->id,
                'booking_item_id' => $lockedItem->id,
                'customer_id' => $persistedBooking->customer_id,
                'user_id' => $user->id,
                'client_event_id' => $normalized['client_event_id'],
                'event_type' => $normalized['event_type'],
                'occurred_at' => $normalized['occurred_at'],
                'actual_start_time' => $normalized['actual_start_time'] ?? null,
                'actual_return_time' => $normalized['actual_return_time'] ?? null,
                'distance_km' => $normalized['distance_km'] ?? null,
                'waiting_minutes' => $normalized['waiting_minutes'] ?? null,
                'payload_hash' => $payloadHash,
                'ip_address' => $requestContext['ip_address'] ?? null,
                'user_agent' => isset($requestContext['user_agent'])
                    ? mb_substr((string) $requestContext['user_agent'], 0, 500)
                    : null,
            ]);

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'customer_mobile_activity_recorded',
                'entity' => 'BookingItem',
                'entity_id' => $lockedItem->id,
                'timestamp' => Carbon::now('UTC'),
                'details' => [
                    'booking_id' => $persistedBooking->id,
                    'customer_id' => $persistedBooking->customer_id,
                    'activity_id' => $activity->id,
                    'client_event_id' => $activity->client_event_id,
                    'event_type' => $activity->event_type,
                    'payload_hash' => $payloadHash,
                    'telemetry' => [
                        'actual_start_time' => $normalized['actual_start_time'] ?? null,
                        'actual_return_time' => $normalized['actual_return_time'] ?? null,
                        'distance_km' => $normalized['distance_km'] ?? null,
                        'waiting_minutes' => $normalized['waiting_minutes'] ?? null,
                    ],
                    'pricing_source' => 'customer_mobile_activity',
                    'pricing_source_priority' => 4,
                    'higher_priority_sources' => [
                        'driver_mobile_activity',
                        'system_activity',
                        'dispatch_return',
                    ],
                ],
            ]);

            return [
                'activity' => $activity,
                'summary' => $this->summaryForItem((string) $lockedItem->id),
                'replayed' => false,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function summaryForItem(string $bookingItemId): array
    {
        return $this->summarizeActivities(
            BookingCustomerMobileActivity::query()
                ->where('booking_item_id', $bookingItemId)
                ->orderBy('occurred_at')
                ->orderBy('created_at')
                ->get()
        );
    }

    /**
     * Pure reducer kept public so mobile payload behavior is testable without a DB.
     * Cumulative distance and waiting values use the greatest persisted snapshot;
     * start/end times use the earliest start and latest return.
     *
     * @param iterable<array<string, mixed>|object> $activities
     * @return array<string, mixed>
     */
    public function summarizeActivities(iterable $activities): array
    {
        $starts = [];
        $returns = [];
        $distances = [];
        $waiting = [];
        $occurred = [];
        $eventCount = 0;

        foreach ($activities as $activity) {
            $eventCount++;
            $start = data_get($activity, 'actual_start_time');
            $return = data_get($activity, 'actual_return_time');
            $distance = data_get($activity, 'distance_km');
            $waitingMinutes = data_get($activity, 'waiting_minutes');
            $occurredAt = data_get($activity, 'occurred_at');

            if ($start) {
                $starts[] = Carbon::parse($start)->utc();
            }
            if ($return) {
                $returns[] = Carbon::parse($return)->utc();
            }
            if (is_numeric($distance)) {
                $distances[] = (float) $distance;
            }
            if (is_numeric($waitingMinutes)) {
                $waiting[] = (int) $waitingMinutes;
            }
            if ($occurredAt) {
                $occurred[] = Carbon::parse($occurredAt)->utc();
            }
        }

        $startedAt = $starts ? collect($starts)->sort()->first() : null;
        $returnedAt = $returns ? collect($returns)->sort()->last() : null;

        return [
            'source' => 'customer_mobile_activity',
            'actual_start_time' => $startedAt?->toIso8601String(),
            'actual_return_time' => $returnedAt?->toIso8601String(),
            'distance_km' => $distances ? max($distances) : null,
            'waiting_minutes' => $waiting ? max($waiting) : null,
            'event_count' => $eventCount,
            'last_occurred_at' => $occurred ? collect($occurred)->sort()->last()?->toIso8601String() : null,
            'complete' => $startedAt && $returnedAt && $returnedAt->greaterThanOrEqualTo($startedAt),
        ];
    }

    /** @return array<string, mixed> */
    public function normalizePayload(array $payload): array
    {
        $normalized = [
            'client_event_id' => strtolower((string) $payload['client_event_id']),
            'event_type' => (string) $payload['event_type'],
            'occurred_at' => Carbon::parse($payload['occurred_at'])->utc()->toIso8601String(),
        ];

        if ($normalized['event_type'] === 'trip_started' && empty($payload['actual_start_time'])) {
            $payload['actual_start_time'] = $normalized['occurred_at'];
        }
        if ($normalized['event_type'] === 'trip_completed' && empty($payload['actual_return_time'])) {
            $payload['actual_return_time'] = $normalized['occurred_at'];
        }

        foreach (['actual_start_time', 'actual_return_time'] as $field) {
            if (!empty($payload[$field])) {
                $normalized[$field] = Carbon::parse($payload[$field])->utc()->toIso8601String();
            }
        }
        if (array_key_exists('distance_km', $payload) && $payload['distance_km'] !== null) {
            $normalized['distance_km'] = round((float) $payload['distance_km'], 3);
        }
        if (array_key_exists('waiting_minutes', $payload) && $payload['waiting_minutes'] !== null) {
            $normalized['waiting_minutes'] = (int) $payload['waiting_minutes'];
        }

        $latestAllowedTime = Carbon::now('UTC')->addMinutes(5);
        $occurredAt = Carbon::parse($normalized['occurred_at']);
        if ($occurredAt->greaterThan($latestAllowedTime)) {
            throw new \DomainException('occurred_at cannot be more than five minutes in the future.');
        }

        foreach (['actual_start_time', 'actual_return_time'] as $field) {
            if (isset($normalized[$field]) && Carbon::parse($normalized[$field])->greaterThan($latestAllowedTime)) {
                throw new \DomainException("{$field} cannot be more than five minutes in the future.");
            }
        }

        $start = isset($normalized['actual_start_time'])
            ? Carbon::parse($normalized['actual_start_time'])
            : null;
        $return = isset($normalized['actual_return_time'])
            ? Carbon::parse($normalized['actual_return_time'])
            : null;
        if ($start && $return && $return->lessThan($start)) {
            throw new \DomainException('actual_return_time must be on or after actual_start_time.');
        }

        if (!array_intersect(
            ['actual_start_time', 'actual_return_time', 'distance_km', 'waiting_minutes'],
            array_keys($normalized)
        )) {
            throw new \DomainException('At least one activity metric is required.');
        }

        return $normalized;
    }

    /** @param array<string, mixed> $normalized */
    public function payloadHash(array $normalized): string
    {
        ksort($normalized);

        return hash('sha256', json_encode($normalized, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function assertOwnership(Booking $booking, BookingItem $bookingItem, User $user): void
    {
        $customer = $booking->relationLoaded('customer')
            ? $booking->customer
            : $booking->customer()->first();

        if (
            !$customer
            || (string) $customer->user_id !== (string) $user->id
            || (string) $bookingItem->booking_id !== (string) $booking->id
        ) {
            throw new AuthorizationException('The booking does not belong to the authenticated customer.');
        }

        if (in_array(strtolower((string) $booking->status), ['cancelled', 'completed'], true)) {
            throw new \DomainException('Activity cannot be recorded after a booking is cancelled or completed.');
        }
    }

    /**
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $priorSummary
     */
    private function assertMonotonicCumulativeMetrics(array $normalized, array $priorSummary): void
    {
        foreach (['distance_km', 'waiting_minutes'] as $field) {
            if (
                isset($normalized[$field], $priorSummary[$field])
                && (float) $normalized[$field] < (float) $priorSummary[$field]
            ) {
                throw new \DomainException("{$field} is cumulative and cannot decrease.");
            }
        }

        $priorStart = $priorSummary['actual_start_time'] ?? null;
        $priorReturn = $priorSummary['actual_return_time'] ?? null;
        $newStart = $normalized['actual_start_time'] ?? null;
        $newReturn = $normalized['actual_return_time'] ?? null;
        if ($priorStart && $newReturn && Carbon::parse($newReturn)->lessThan(Carbon::parse($priorStart))) {
            throw new \DomainException('actual_return_time cannot be before the recorded trip start.');
        }
        if ($priorReturn && $newStart && Carbon::parse($newStart)->greaterThan(Carbon::parse($priorReturn))) {
            throw new \DomainException('actual_start_time cannot be after the recorded trip return.');
        }
    }
}
