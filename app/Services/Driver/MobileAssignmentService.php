<?php

namespace App\Services\Driver;

use App\Enums\TripPhase;
use App\Events\AssignmentStatusChanged;
use App\Models\Driver\Driver;
use App\Models\Driver\DriverSession;
use App\Models\DriverAssignment;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
        $this->reconcileCompletedBookingAssignments($driver);
        $query = $this->baseAssignmentQuery($driver);

        if (!empty($filters['status'])) {
            $statusFilter = strtolower((string) $filters['status']);

            switch ($statusFilter) {
                // Mobile app friendly alias for upcoming/pending hires.
                case 'pending':
                case 'upcoming':
                    $query->whereIn('status', ['active', 'pending_approval', 'confirmed', 'approved'])
                        ->whereNotIn('trip_phase', [TripPhase::COMPLETED, TripPhase::DECLINED]);
                    $this->excludeTerminalBookings($query);
                    break;

                case 'in_progress':
                    $query->whereIn('trip_phase', [TripPhase::ACCEPTED, TripPhase::PICKUP_ARRIVED, TripPhase::IN_PROGRESS]);
                    $this->excludeTerminalBookings($query);
                    break;

                default:
                    $query->where('status', $filters['status']);
                    if (!in_array($statusFilter, ['completed', 'cancelled', 'declined'], true)) {
                        $this->excludeTerminalBookings($query);
                    }
                    break;
            }
        } else {
            // The assignments endpoint is the operational inbox. Completed
            // hires belong to the dedicated /hires history endpoint; returning
            // them here makes older mobile clients reopen the end-hire screen.
            $query->whereIn('status', ['active', 'pending_approval', 'confirmed', 'approved'])
                ->whereNotIn('trip_phase', [TripPhase::COMPLETED, TripPhase::DECLINED]);
            $this->excludeTerminalBookings($query);
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
        $this->reconcileCompletedBookingAssignments($driver);
        $now = Carbon::now();

        $query = $this->baseAssignmentQuery($driver)
            ->whereIn('status', ['active', 'confirmed', 'approved'])
            ->whereNotIn('trip_phase', [TripPhase::COMPLETED, TripPhase::DECLINED]);
        $this->excludeTerminalBookings($query);

        return $query
            ->where('assigned_from', '<=', $now)
            ->where(function (Builder $query) use ($now) {
                $query->whereNull('assigned_to')
                    ->orWhere('assigned_to', '>=', $now);
            })
            ->orderByDesc('assigned_from')
            ->first();
    }

    /**
     * Return the driver's operational trip without allowing a completed parent
     * booking to leak back through heartbeat or status responses.
     */
    public function getActiveTripAssignment(Driver $driver): ?DriverAssignment
    {
        $this->reconcileCompletedBookingAssignments($driver);

        return $this->baseAssignmentQuery($driver)
            ->activeTripPhase()
            ->orderByDesc('updated_at')
            ->first();
    }

    public function getPendingAssignments(Driver $driver): Collection
    {
        $this->reconcileCompletedBookingAssignments($driver);

        $query = $this->baseAssignmentQuery($driver)
            ->whereIn('status', ['active', 'pending_approval', 'confirmed', 'approved'])
            ->whereNotIn('trip_phase', [TripPhase::COMPLETED, TripPhase::DECLINED]);
        $this->excludeTerminalBookings($query);

        return $query->orderBy('assigned_from')->get();
    }

    /**
     * Repair stale assignment/session rows left behind when a booking was
     * completed administratively before the mobile assignment was closed.
     */
    public function reconcileCompletedBookingAssignments(Driver $driver): int
    {
        return DB::transaction(function () use ($driver) {
            $assignments = DriverAssignment::query()
                ->where('driver_id', $driver->id)
                ->where(function (Builder $query) {
                    $query->whereNull('trip_phase')
                        ->orWhereNotIn('trip_phase', [TripPhase::COMPLETED, TripPhase::DECLINED]);
                })
                ->whereHas('booking', fn (Builder $query) => $query->where('status', 'completed'))
                ->with('booking:id,completed_at')
                ->lockForUpdate()
                ->get();

            if ($assignments->isEmpty()) {
                return 0;
            }

            foreach ($assignments as $assignment) {
                $completedAt = $assignment->booking?->completed_at ?? Carbon::now('UTC');
                $assignment->update([
                    'status' => 'completed',
                    'trip_phase' => TripPhase::COMPLETED,
                    'trip_completed_at' => $assignment->trip_completed_at ?? $completedAt,
                    'actual_end' => $assignment->actual_end ?? $completedAt,
                ]);
            }

            DriverSession::query()
                ->whereIn('assignment_id', $assignments->pluck('id'))
                ->update(['assignment_id' => null]);
            $driver->unsetRelation('activeSession');

            Log::warning('Reconciled stale driver assignments for completed bookings', [
                'driver_id' => $driver->id,
                'assignment_ids' => $assignments->pluck('id')->all(),
            ]);

            return $assignments->count();
        });
    }

    /**
     * Prevent stale assignment rows from resurfacing completed/cancelled hires
     * in driver-facing active and upcoming views.
     */
    private function excludeTerminalBookings(Builder $query): void
    {
        $query->where(function (Builder $assignmentQuery) {
            $assignmentQuery->whereDoesntHave('booking')
                ->orWhereHas('booking', fn (Builder $bookingQuery) => $bookingQuery
                    ->whereNotIn('status', $this->terminalBookingStatuses()));
        });
    }

    private function terminalBookingStatuses(): array
    {
        return ['completed', 'cancelled', 'booking_cancelled', 'booking_rejected', 'rejected'];
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
        $this->assertAssignmentOwnership($driver, $assignment);

        if ($assignment->status === 'confirmed') {
            throw new \InvalidArgumentException('ASSIGNMENT_ALREADY_CONFIRMED');
        }

        if (!in_array($assignment->status, ['active', 'pending_approval'])) {
            throw new \InvalidArgumentException('ASSIGNMENT_INVALID_STATE');
        }

        return DB::transaction(function () use ($driver, $assignment) {
            $now = Carbon::now('UTC');

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

            try {
                $this->notificationService->acknowledgeAssignment($updated, (string) $driver->id, 'accepted');
            } catch (\Throwable $exception) {
                Log::warning('Assignment accepted but notification acknowledgement could not be recorded', [
                    'assignment_id' => $updated->id,
                    'driver_id' => $driver->id,
                    'error' => $exception->getMessage(),
                ]);
            }

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
        $this->assertAssignmentOwnership($driver, $assignment);

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

    private function assertAssignmentOwnership(Driver $driver, DriverAssignment $assignment): void
    {
        if ((string) $assignment->driver_id !== (string) $driver->id) {
            throw new \InvalidArgumentException('ASSIGNMENT_NOT_FOUND');
        }
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

        $paymentDetails = $this->resolvePaymentDetails($assignment);
        $fareAmount = $this->resolveFareAmount($assignment);
        $pricingMetrics = $this->buildPricingMetrics($bookingItem, $assignment);
        $notification = Schema::hasTable('driver_assignment_notifications')
            ? \App\Models\Driver\DriverAssignmentNotification::query()
                ->where('assignment_id', $assignment->id)
                ->where('driver_id', $assignment->driver_id)
                ->first(['id', 'acknowledged_at', 'acknowledgement_source'])
            : null;

        // Driver responses are an explicit projection. Never serialize loaded booking,
        // pricing, tracking, approval, or internal assignment relations implicitly.
        $payload = [
            'id' => $assignment->id,
            'driver_id' => $assignment->driver_id,
            'booking_id' => $assignment->booking_id,
            'booking_item_id' => $assignment->booking_item_id,
            'parent_assignment_id' => $assignment->parent_assignment_id,
            'status' => $assignment->status,
            'trip_phase' => $assignment->trip_phase?->value,
            'assignment_type' => $assignment->assignment_type,
            'assignment_notes' => $assignment->assignment_notes,
            'special_requirements' => $assignment->special_requirements,
            'pickup_arrived_at' => $assignment->pickup_arrived_at?->toIso8601String(),
            'trip_started_at' => $assignment->trip_started_at?->toIso8601String(),
            'created_at' => $assignment->created_at?->toIso8601String(),
            'updated_at' => $assignment->updated_at?->toIso8601String(),
            'notification_id' => $notification?->id,
            'notification' => $notification ? [
                'id' => $notification->id,
                'acknowledged_at' => $notification->acknowledged_at?->toIso8601String(),
                'acknowledgement_source' => $notification->acknowledgement_source,
                'acknowledgement_required' => $notification->acknowledged_at === null,
            ] : null,
        ];
        $payload['payment_type'] = $paymentDetails['payment_type'];
        $payload['payment_collection_method'] = $paymentDetails['payment_collection_method'];
        $payload['payment_collection_status'] = $paymentDetails['payment_collection_status'];
        $payload['payment_collection_required'] = $paymentDetails['payment_collection_required'];
        $payload['payment_instruction'] = $paymentDetails['payment_instruction'];
        $payload['amount_to_collect'] = $paymentDetails['payment_collection_required']
            ? max(0, round($fareAmount - (float)($booking?->payment_collected_amount ?? 0), 2))
            : null;
        $payload['fare_amount'] = $fareAmount;
        $payload['total_amount'] = $fareAmount;
        $payload['currency'] = $bookingItem?->currency ?? $booking?->currency;
        $payload['duration_days'] = $pricingMetrics['duration_days'];
        $payload['duration_hours'] = $pricingMetrics['duration_hours'];
        $payload['duration_minutes'] = $pricingMetrics['duration_minutes'];
        $payload['hire_km'] = $pricingMetrics['hire_km'];
        $payload['waiting_hours'] = $pricingMetrics['waiting_hours'];
        $payload['waiting_minutes'] = $pricingMetrics['waiting_minutes'];
        $payload['waiting_charge'] = $pricingMetrics['waiting_charge'];
        $payload['pricing_metrics'] = $this->driverPricingMetrics($pricingMetrics);
        $payload['booking_number'] = $booking?->booking_number;
        $payload['service_type_name'] = $bookingItem?->serviceType?->name ?? $assignment->service_type;
        $payload['customer_name'] = $this->resolveCustomerName($assignment);
        $payload['customer_phone'] = $customerUser?->phone;
        $payload['customer_email'] = $customerUser?->email;
        $payload['pickup_location_label'] = $this->extractLocationLabel($bookingItem?->pickup_location);
        $payload['dropoff_location_label'] = $this->extractLocationLabel($bookingItem?->dropoff_location);
        $itemMetadata = is_array($bookingItem?->metadata) ? $bookingItem->metadata : [];
        $isOpenPackage = ($itemMetadata['trip_mode'] ?? null) === 'open_package';
        $payload['trip_mode'] = $isOpenPackage ? 'open_package' : 'fixed_route';
        $payload['destination_known'] = !$isOpenPackage;
        $payload['driver_message'] = $isOpenPackage
            ? 'Open chauffeur package - destination decided during trip'
            : null;
        $payload['package'] = $this->mapPackageForMobile($itemMetadata);
        $stops = $this->tripTrackingService->ensureAssignmentStops($assignment);
        $payload['is_multi_stop'] = $stops->count() > 2;
        $payload['route_stops'] = $this->tripTrackingService->mapStopsForMobile($stops);
        $payload['allowed_actions'] = $this->tripTrackingService->getAssignmentAllowedActions($assignment, $stops);
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
        return $this->resolvePaymentDetails($assignment)['payment_type'];
    }

    private function resolvePaymentDetails(DriverAssignment $assignment): array
    {
        $booking = $assignment->booking;
        $method = strtolower((string) ($booking?->payment_collection_method ?? $booking?->payment_method ?? $booking?->payment_type ?? ''));
        $status = strtolower((string) ($booking?->payment_collection_status ?? $booking?->payment_status ?? 'pending'));

        if (in_array($method, ['monthly_invoice', 'corporate', 'credit', 'company_billing'], true) || str_contains($method, 'corp')) {
            return [
                'payment_type' => 'corporate',
                'payment_collection_method' => 'monthly_invoice',
                'payment_collection_status' => $status ?: 'billable',
                'payment_collection_required' => false,
                'payment_instruction' => 'Corporate billing - do not collect cash',
            ];
        }

        if (in_array($method, ['account_credit', 'customer_credit', 'invoice', 'complimentary', 'waived'], true)) {
            return [
                'payment_type' => $method === 'account_credit' ? 'customer_credit' : $method,
                'payment_collection_method' => $method,
                'payment_collection_status' => $status,
                'payment_collection_required' => false,
                'payment_instruction' => in_array($method, ['complimentary', 'waived'], true)
                    ? 'No payment collection required'
                    : 'Account billing - do not collect cash',
            ];
        }

        if (in_array($method, ['online', 'webxpay', 'credit_card', 'debit_card', 'stripe', 'paypal'], true)) {
            $paid = in_array($status, ['online_paid', 'paid', 'success'], true) || $booking?->payment_status === 'paid';
            return [
                'payment_type' => 'online',
                'payment_collection_method' => 'online',
                'payment_collection_status' => $status ?: 'pending',
                'payment_collection_required' => false,
                'payment_instruction' => $paid ? 'Paid online' : 'Online payment pending',
            ];
        }

        return [
            'payment_type' => 'cash',
            'payment_collection_method' => $method ?: 'cash_to_driver',
            'payment_collection_status' => $status ?: 'pending',
            'payment_collection_required' => true,
            'payment_instruction' => in_array($method,['advance_then_balance','deposit_then_balance'],true)
                ? 'Collect only the outstanding balance from the customer'
                : 'Collect payment from customer',
        ];
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

    private function buildPricingMetrics($bookingItem, ?DriverAssignment $assignment = null): array
    {
        $pricingBreakdown = is_array($bookingItem?->pricing_breakdown) ? $bookingItem->pricing_breakdown : [];
        $metadata = is_array($bookingItem?->metadata) ? $bookingItem->metadata : [];
        $distanceDetails = $metadata['distance_details']
            ?? $pricingBreakdown['distance_details']
            ?? $pricingBreakdown['base_pricing']['distance_details']
            ?? null;
        $distanceDetails = is_array($distanceDetails) ? $distanceDetails : [];
        $kmCalculations = $pricingBreakdown['km_calculations']
            ?? $pricingBreakdown['base_pricing']['km_calculations']
            ?? ($distanceDetails['km_calculations'] ?? null);
        $kmCalculations = is_array($kmCalculations) ? $kmCalculations : [];
        $summary = is_array($pricingBreakdown['summary'] ?? null) ? $pricingBreakdown['summary'] : [];
        $basePricing = is_array($pricingBreakdown['base_pricing'] ?? null) ? $pricingBreakdown['base_pricing'] : [];
        $finalPricing = is_array($pricingBreakdown['final_pricing'] ?? null)
            ? $pricingBreakdown['final_pricing']
            : [];
        $finalAuditInputs = is_array($finalPricing['audit']['inputs'] ?? null)
            ? $finalPricing['audit']['inputs']
            : [];
        $finalDistanceDetails = is_array($finalPricing['distance_details'] ?? null)
            ? $finalPricing['distance_details']
            : [];
        $finalKmCalculations = is_array($finalPricing['km_calculations'] ?? null)
            ? $finalPricing['km_calculations']
            : [];
        $variables = $finalPricing['calculation_metadata']['resolved_variables']
            ?? $pricingBreakdown['calculation_metadata']['resolved_variables']
            ?? $basePricing['calculation_metadata']['resolved_variables']
            ?? $pricingBreakdown['calculation_metadata']['variables_used']
            ?? $basePricing['calculation_metadata']['variables_used']
            ?? [];
        $variables = is_array($variables) ? $variables : [];

        $hireKm = $this->firstNumeric([
            $finalAuditInputs['distance_km'] ?? null,
            $finalDistanceDetails['journey_distance'] ?? null,
            $finalDistanceDetails['actual_journey_distance'] ?? null,
            $finalKmCalculations['journey_distance'] ?? null,
            $assignment?->total_distance_km,
            $distanceDetails['journey_distance'] ?? null,
            $distanceDetails['actual_journey_distance'] ?? null,
            $kmCalculations['journey_distance'] ?? null,
            $kmCalculations['actual_journey_distance'] ?? null,
            $pricingBreakdown['total_journey_distance_km'] ?? null,
            $metadata['total_journey_distance_km'] ?? null,
        ]);

        $waitingSeconds = $assignment?->total_waiting_time_seconds;
        $waitingHours = $this->firstNumeric([
            isset($finalAuditInputs['waiting_minutes'])
                ? ((float) $finalAuditInputs['waiting_minutes'] / 60)
                : null,
            $variables['waiting_hours'] ?? null,
            $pricingBreakdown['waiting_hours'] ?? null,
            $basePricing['waiting_hours'] ?? null,
            $waitingSeconds !== null ? ((int) $waitingSeconds / 3600) : null,
        ]);
        $waitingMinutes = $this->firstNumeric([
            $variables['waiting_minutes'] ?? null,
            $pricingBreakdown['waiting_minutes'] ?? null,
            $basePricing['waiting_minutes'] ?? null,
            $waitingSeconds !== null ? ((int) $waitingSeconds / 60) : null,
            $waitingHours !== null ? $waitingHours * 60 : null,
        ]);

        $waitingRate = $this->firstNumeric([
            $variables['waiting_charge_per_hour'] ?? null,
            $variables['waiting_rate_per_hour'] ?? null,
            $pricingBreakdown['waiting_charge_per_hour'] ?? null,
            $basePricing['waiting_charge_per_hour'] ?? null,
        ]);

        $waitingCharge = $this->firstNumeric([
            $pricingBreakdown['waiting_charge'] ?? null,
            $basePricing['waiting_charge'] ?? null,
            $waitingHours !== null && $waitingRate !== null ? $waitingHours * $waitingRate : null,
        ]);

        return [
            'currency' => $bookingItem?->currency,
            'base_amount' => $this->firstNumeric([
                $summary['base_total'] ?? null,
                $basePricing['base_amount'] ?? null,
                $basePricing['total_amount_without_customizations'] ?? null,
                $bookingItem?->unit_price,
            ]),
            'total_amount' => $this->firstNumeric([
                $finalPricing['audit']['final_base'] ?? null,
                $finalPricing['total_amount'] ?? null,
                $summary['grand_total'] ?? null,
                $summary['total'] ?? null,
                $basePricing['total_amount'] ?? null,
                $bookingItem?->total_price,
            ]),
            'duration_days' => $bookingItem?->duration_days !== null ? (int) $bookingItem->duration_days : null,
            'duration_hours' => $bookingItem?->duration_hours !== null ? (int) $bookingItem->duration_hours : null,
            'duration_minutes' => $this->firstNumeric([
                $finalAuditInputs['duration_minutes'] ?? null,
                $assignment?->trip_started_at && $assignment?->trip_completed_at
                    ? $assignment->trip_started_at->diffInSeconds($assignment->trip_completed_at) / 60
                    : null,
                $bookingItem?->duration_minutes,
                $bookingItem?->duration_hours !== null ? (int) $bookingItem->duration_hours * 60 : null,
            ]),
            'journey_duration_seconds' => $this->firstNumeric([
                isset($finalAuditInputs['duration_minutes'])
                    ? ((float) $finalAuditInputs['duration_minutes'] * 60)
                    : null,
                $assignment?->trip_started_at && $assignment?->trip_completed_at
                    ? $assignment->trip_started_at->diffInSeconds($assignment->trip_completed_at)
                    : null,
                $metadata['journey_duration_seconds'] ?? null,
                $distanceDetails['journey_duration_seconds'] ?? null,
                $distanceDetails['duration_seconds'] ?? null,
            ]),
            'hire_km' => $hireKm,
            'included_km' => $this->firstNumeric([
                $finalDistanceDetails['allowed_km'] ?? null,
                $finalKmCalculations['allowed_km'] ?? null,
                $distanceDetails['allowed_km'] ?? null,
                $kmCalculations['allowed_km'] ?? null,
                $distanceDetails['included_km'] ?? null,
            ]),
            'extra_km' => $this->firstNumeric([
                $finalDistanceDetails['extra_km'] ?? null,
                $finalKmCalculations['extra_km'] ?? null,
                $distanceDetails['extra_km'] ?? null,
                $kmCalculations['extra_km'] ?? null,
            ]),
            'waiting_hours' => $waitingHours,
            'waiting_minutes' => $waitingMinutes,
            'waiting_rate_per_hour' => $waitingRate,
            'waiting_charge' => $waitingCharge,
            'pricing_breakdown' => $pricingBreakdown,
            'distance_details' => $distanceDetails ?: null,
        ];
    }

    /**
     * Allowlist only execution-relevant usage metrics. Internal pricing graphs,
     * contractual route legs, rates, margins, and formula inputs stay server-side.
     */
    private function driverPricingMetrics(array $metrics): array
    {
        return collect($metrics)->only([
            'currency',
            'duration_days',
            'duration_hours',
            'duration_minutes',
            'journey_duration_seconds',
            'hire_km',
            'included_km',
            'extra_km',
            'waiting_hours',
            'waiting_minutes',
            'waiting_charge',
        ])->all();
    }

    private function firstNumeric(array $values): ?float
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '' && is_numeric($value)) {
                return round((float) $value, 3);
            }
        }

        return null;
    }

    private function mapPackageForMobile(array $metadata): ?array
    {
        $packageId = $metadata['service_package_id'] ?? $metadata['package_id'] ?? null;
        if (!$packageId) {
            return null;
        }

        $package = \App\Models\Service\ServicePackage::find($packageId);
        if (!$package) {
            return ['id' => $packageId];
        }

        return [
            'id' => $package->id,
            'name' => $package->name,
            'code' => $package->code,
            'description' => $package->description,
            'included_km_per_day' => $package->max_km_per_day !== null ? (float) $package->max_km_per_day : null,
            'included_km_per_package' => $package->max_km_per_package !== null ? (float) $package->max_km_per_package : null,
            'included_hours' => $package->default_duration_hours,
            'included_minutes' => ((int) $package->default_duration_hours * 60)
                + (int) $package->default_duration_minutes,
            'rate_type' => $package->rate_type,
        ];
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
