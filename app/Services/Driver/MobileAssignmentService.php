<?php

namespace App\Services\Driver;

use App\Enums\TripPhase;
use App\Events\AssignmentStatusChanged;
use App\Models\Driver\Driver;
use App\Models\DriverAssignment;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mobile Assignment Service
 *
 * Handles driver-facing assignment operations for the mobile API:
 * listing, accepting, declining, and creating assignments.
 *
 * @see Requirements 1.1, 1.2, 1.4, 1.5, 1.6, 2.1–2.5, 12.7
 */
class MobileAssignmentService
{
    public function __construct(
        private NotificationTriggerService $notificationService,
        private TripTrackingService $tripTrackingService
    ) {}

    private function baseAssignmentQuery(Driver $driver): Builder
    {
        return DriverAssignment::where('driver_id', $driver->id)
            ->with([
                'booking',
                'booking.customer.user',
                'bookingItem',
                'bookingItem.serviceType',
                'bookingItem.vehicle.owner',
                'bookingItem.vehicle.group',
            ]);
    }

    /**
     * Get paginated assignments for a driver with optional status filter.
     */
    public function getDriverAssignments(Driver $driver, array $filters = []): LengthAwarePaginator
    {
        $query = $this->baseAssignmentQuery($driver);

        if (!empty($filters['status'])) {
            $statusFilter = strtolower((string) $filters['status']);

            switch ($statusFilter) {
                // Mobile app friendly alias for upcoming/pending hires.
                case 'pending':
                case 'upcoming':
                    $query->whereIn('status', ['active', 'pending_approval', 'confirmed', 'approved'])
                        ->whereNotIn('trip_phase', [TripPhase::COMPLETED, TripPhase::DECLINED]);
                    break;

                case 'in_progress':
                    $query->whereIn('trip_phase', [TripPhase::ACCEPTED, TripPhase::PICKUP_ARRIVED, TripPhase::IN_PROGRESS]);
                    break;

                default:
                    $query->where('status', $filters['status']);
                    break;
            }
        }

        if (!empty($filters['date'])) {
            $date = Carbon::parse($filters['date'])->toDateString();
            $query->whereDate('assigned_from', '<=', $date)
                ->whereDate('assigned_to', '>=', $date);
        }

        if (!empty($filters['from'])) {
            $from = Carbon::parse($filters['from']);
            $query->where(function (Builder $q) use ($from) {
                $q->whereNull('assigned_to')
                    ->orWhere('assigned_to', '>=', $from);
            });
        }

        if (!empty($filters['to'])) {
            $to = Carbon::parse($filters['to']);
            $query->where('assigned_from', '<=', $to);
        }

        $query->orderBy('assigned_from', 'desc');

        $perPage = (int) ($filters['per_page'] ?? 15);
        if ($perPage <= 0) {
            $perPage = 15;
        }

        return $query->paginate($perPage);
    }

    /**
     * Get the current active assignment for a driver (time-bounded, status active).
     */
    public function getCurrentAssignment(Driver $driver): ?DriverAssignment
    {
        $now = Carbon::now();

        return $this->baseAssignmentQuery($driver)
            ->whereIn('status', ['active', 'confirmed', 'approved'])
            ->where('assigned_from', '<=', $now)
            ->where(function (Builder $query) use ($now) {
                $query->whereNull('assigned_to')
                    ->orWhere('assigned_to', '>=', $now);
            })
            ->orderByDesc('assigned_from')
            ->first();
    }

    /**
     * Get completed hire history for a driver.
     */
    public function getDriverHires(Driver $driver, array $filters = []): LengthAwarePaginator
    {
        $query = $this->baseAssignmentQuery($driver)
            ->where('trip_phase', TripPhase::COMPLETED);

        if (!empty($filters['date'])) {
            $query->whereDate('trip_completed_at', Carbon::parse($filters['date'])->toDateString());
        }

        if (!empty($filters['from'])) {
            $query->where('trip_completed_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (!empty($filters['to'])) {
            $query->where('trip_completed_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        $query->orderByDesc('trip_completed_at');

        $perPage = (int) ($filters['per_page'] ?? 15);
        if ($perPage <= 0) {
            $perPage = 15;
        }

        return $query->paginate($perPage);
    }

    /**
     * Accept a driver assignment.
     *
     * Validates state, transitions to confirmed, sets trip_phase to accepted,
     * and links the active DriverSession.
     *
     * @throws \InvalidArgumentException
     */
    public function acceptAssignment(Driver $driver, DriverAssignment $assignment): DriverAssignment
    {
        if ($assignment->status === 'confirmed') {
            throw new \InvalidArgumentException('ASSIGNMENT_ALREADY_CONFIRMED');
        }

        if (!in_array($assignment->status, ['active', 'pending_approval'])) {
            throw new \InvalidArgumentException('ASSIGNMENT_INVALID_STATE');
        }

        return DB::transaction(function () use ($driver, $assignment) {
            $now = Carbon::now();

            $assignment->update([
                'status' => 'confirmed',
                'confirmed_at' => $now,
                'confirmed_by' => $driver->user_id,
                'trip_phase' => TripPhase::ACCEPTED,
            ]);

            // Link active session to assignment
            $session = $driver->activeSession;
            if ($session) {
                $session->update(['assignment_id' => $assignment->id]);
            }

            $updated = $assignment->fresh([
                'booking',
                'bookingItem',
                'bookingItem.vehicle.owner',
                'bookingItem.vehicle.group',
            ]);

            $this->tripTrackingService->ensureAssignmentStops($updated);

            // Broadcast status change to admin panel for real-time sync
            $this->broadcastStatusChange($updated, 'accepted', $driver);

            return $updated;
        });
    }

    /**
     * Decline a driver assignment.
     *
     * @throws \InvalidArgumentException
     */
    public function declineAssignment(Driver $driver, DriverAssignment $assignment, string $reason): DriverAssignment
    {
        if (!in_array($assignment->status, ['active', 'pending_approval'])) {
            throw new \InvalidArgumentException('ASSIGNMENT_INVALID_STATE');
        }

        $assignment->update([
            'status' => 'declined',
            'trip_phase' => TripPhase::DECLINED,
            'decline_reason' => $reason,
        ]);

        $updated = $assignment->fresh();

        // Broadcast status change to admin panel for real-time sync
        $this->broadcastStatusChange($updated, 'declined', $driver);

        return $updated;
    }

    /**
     * Get all drivers with computed Driver_Status (online/offline/on_hire).
     */
    public function getDriversWithStatus(): Collection
    {
        return Driver::with(['activeSession'])
            ->get()
            ->map(function (Driver $driver) {
                $status = 'offline';

                if ($driver->is_online && $driver->activeSession) {
                    // Check if driver has an in-progress trip
                    $hasActiveTrip = DriverAssignment::where('driver_id', $driver->id)
                        ->where('trip_phase', TripPhase::IN_PROGRESS)
                        ->exists();

                    $status = $hasActiveTrip ? 'on_hire' : 'online';
                }

                $driver->setAttribute('driver_status', $status);

                return $driver;
            });
    }

    /**
     * Create a new driver assignment and trigger notification.
     */
    public function createAssignment(array $data): DriverAssignment
    {
        return DB::transaction(function () use ($data) {
            $assignment = DriverAssignment::create(array_merge($data, [
                'trip_phase' => TripPhase::ACTIVE,
                'status' => $data['status'] ?? 'active',
            ]));

            // Trigger notification to driver
            $this->notificationService->sendAssignmentNotification($assignment);

            return $assignment;
        });
    }

    /**
     * Broadcast assignment status change to the admin panel channel.
     *
     * Fires an AssignmentStatusChanged event so the admin panel
     * receives real-time updates when a driver accepts or declines.
     *
     * @see Requirements 14.5, 14.6
     */
    private function broadcastStatusChange(DriverAssignment $assignment, string $action, Driver $driver): void
    {
        try {
            broadcast(new AssignmentStatusChanged([
                'assignment_id' => $assignment->id,
                'booking_id' => $assignment->booking_id,
                'booking_item_id' => $assignment->booking_item_id,
                'driver_id' => $driver->id,
                'driver_name' => $driver->user
                    ? trim($driver->user->first_name . ' ' . $driver->user->last_name)
                    : ($driver->code ?? 'Unknown'),
                'action' => $action,
                'status' => $assignment->status,
                'trip_phase' => $assignment->trip_phase?->value,
                'updated_at' => $assignment->updated_at?->toIso8601String(),
            ]));
        } catch (\Exception $e) {
            Log::warning('Failed to broadcast assignment status change to admin', [
                'assignment_id' => $assignment->id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Convert assignment model to a consistent mobile payload.
     */
    public function buildAssignmentPayload(?DriverAssignment $assignment): ?array
    {
        if (!$assignment) {
            return null;
        }

        $assignment->loadMissing([
            'booking',
            'booking.customer.user',
            'bookingItem',
            'bookingItem.serviceType',
            'bookingItem.vehicle.group',
            'stops',
        ]);

        $booking = $assignment->booking;
        $bookingItem = $assignment->bookingItem;
        $customerUser = $booking?->customer?->user;

        $paymentType = $this->resolvePaymentType($assignment);
        $fareAmount = $this->resolveFareAmount($assignment);

        $payload = $assignment->toArray();
        $payload['payment_type'] = $paymentType;
        $payload['fare_amount'] = $fareAmount;
        $payload['total_amount'] = $fareAmount;
        $payload['currency'] = $bookingItem?->currency ?? $booking?->currency;
        $payload['booking_number'] = $booking?->booking_number;
        $payload['service_type_name'] = $bookingItem?->serviceType?->name ?? $assignment->service_type;
        $payload['customer_name'] = $this->resolveCustomerName($assignment);
        $payload['customer_phone'] = $customerUser?->phone;
        $payload['customer_email'] = $customerUser?->email;
        $payload['pickup_location_label'] = $this->extractLocationLabel($bookingItem?->pickup_location);
        $payload['dropoff_location_label'] = $this->extractLocationLabel($bookingItem?->dropoff_location);
        $stops = $this->tripTrackingService->ensureAssignmentStops($assignment);
        $payload['is_multi_stop'] = $stops->count() > 2;
        $payload['route_stops'] = $this->tripTrackingService->mapStopsForMobile($stops);
        $payload['scheduled_from'] = $assignment->assigned_from?->toIso8601String();
        $payload['scheduled_to'] = $assignment->assigned_to?->toIso8601String();
        $payload['trip_completed_at'] = $assignment->trip_completed_at?->toIso8601String();

        return $payload;
    }

    public function mapAssignmentsForMobile(iterable $assignments): array
    {
        $result = [];
        foreach ($assignments as $assignment) {
            $result[] = $this->buildAssignmentPayload($assignment);
        }

        return $result;
    }

    public function getEarningsSummary(Driver $driver): array
    {
        $today = Carbon::today();
        $weekStart = $today->copy()->startOfWeek();
        $weekEnd = $today->copy()->endOfWeek();
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();

        $todayAssignments = $this->completedAssignmentsInRange($driver, $today, $today->copy()->endOfDay());
        $weekAssignments = $this->completedAssignmentsInRange($driver, $weekStart, $weekEnd);
        $monthAssignments = $this->completedAssignmentsInRange($driver, $monthStart, $monthEnd);

        return [
            'today' => [
                'date' => $today->toDateString(),
                'total' => $this->sumAssignmentEarnings($todayAssignments),
                'trip_count' => $todayAssignments->count(),
            ],
            'this_week' => [
                'from' => $weekStart->toDateString(),
                'to' => $weekEnd->toDateString(),
                'total' => $this->sumAssignmentEarnings($weekAssignments),
                'trip_count' => $weekAssignments->count(),
            ],
            'this_month' => [
                'from' => $monthStart->toDateString(),
                'to' => $monthEnd->toDateString(),
                'total' => $this->sumAssignmentEarnings($monthAssignments),
                'trip_count' => $monthAssignments->count(),
            ],
            'currency' => $driver->currency ?? 'LKR',
        ];
    }

    public function getDailyEarnings(Driver $driver, string $date): array
    {
        $targetDate = Carbon::parse($date);
        $assignments = $this->completedAssignmentsInRange($driver, $targetDate->copy()->startOfDay(), $targetDate->copy()->endOfDay());

        return [
            'date' => $targetDate->toDateString(),
            'total' => $this->sumAssignmentEarnings($assignments),
            'trip_count' => $assignments->count(),
            'items' => $this->mapAssignmentsForMobile($assignments),
        ];
    }

    public function getRangeEarnings(Driver $driver, string $from, string $to): array
    {
        $fromDate = Carbon::parse($from)->startOfDay();
        $toDate = Carbon::parse($to)->endOfDay();
        $assignments = $this->completedAssignmentsInRange($driver, $fromDate, $toDate);

        return [
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'total' => $this->sumAssignmentEarnings($assignments),
            'trip_count' => $assignments->count(),
            'items' => $this->mapAssignmentsForMobile($assignments),
        ];
    }

    private function completedAssignmentsInRange(Driver $driver, Carbon $from, Carbon $to): Collection
    {
        return $this->baseAssignmentQuery($driver)
            ->where('trip_phase', TripPhase::COMPLETED)
            ->whereBetween('trip_completed_at', [$from, $to])
            ->orderByDesc('trip_completed_at')
            ->get();
    }

    private function sumAssignmentEarnings(Collection $assignments): float
    {
        return round($assignments->sum(function ($assignment) {
            return $this->resolveFareAmount($assignment);
        }), 2);
    }

    private function resolveCustomerName(DriverAssignment $assignment): ?string
    {
        if (!empty($assignment->customer_name)) {
            return $assignment->customer_name;
        }

        $customerUser = $assignment->booking?->customer?->user;
        if (!$customerUser) {
            return null;
        }

        $name = trim(($customerUser->first_name ?? '') . ' ' . ($customerUser->last_name ?? ''));
        return $name !== '' ? $name : null;
    }

    private function resolvePaymentType(DriverAssignment $assignment): string
    {
        $booking = $assignment->booking;
        $rawType = strtolower((string) ($booking?->payment_type ?? $booking?->payment_method ?? ''));

        if (in_array($rawType, ['cash', 'credit', 'corporate'], true)) {
            return $rawType;
        }

        if (str_contains($rawType, 'corp')) {
            return 'corporate';
        }
        if (str_contains($rawType, 'credit')) {
            return 'credit';
        }
        if (str_contains($rawType, 'cash')) {
            return 'cash';
        }

        return 'cash';
    }

    private function resolveFareAmount(DriverAssignment $assignment): float
    {
        $bookingItem = $assignment->bookingItem;
        if ($bookingItem) {
            $itemTotal = (float) ($bookingItem->total_price ?? 0);
            $addonsTotal = 0.0;

            if (is_array($bookingItem->addons)) {
                foreach ($bookingItem->addons as $addon) {
                    $addonsTotal += (float) ($addon['total_price'] ?? 0);
                }
            }

            $computed = $itemTotal + $addonsTotal;
            if ($computed > 0) {
                return round($computed, 2);
            }
        }

        $booking = $assignment->booking;
        $fallback = (float) ($booking?->total_actual ?? $booking?->total_estimated ?? 0);
        return round($fallback, 2);
    }

    private function extractLocationLabel(mixed $location): ?string
    {
        if (is_array($location)) {
            return $location['address']
                ?? $location['display_name']
                ?? $location['name']
                ?? null;
        }

        if (!is_string($location) || trim($location) === '') {
            return null;
        }

        $decoded = json_decode($location, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded['address']
                ?? $decoded['display_name']
                ?? $decoded['name']
                ?? $location;
        }

        return $location;
    }
}
