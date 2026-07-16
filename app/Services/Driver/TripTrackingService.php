<?php

namespace App\Services\Driver;

use App\Enums\DispatchStatus;
use App\Enums\TripPhase;
use App\Enums\VehicleAvailabilityStatus;
use App\Models\Booking\BookingItem;
use App\Models\Driver\Driver;
use App\Models\Driver\DriverSession;
use App\Models\Driver\RoutePoint;
use App\Models\Vehicle\Vehicle;
use App\Models\DriverAssignment;
use App\Models\DriverAssignmentStop;
use App\Services\BookingLifecycleService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Trip Tracking Service
 *
 * Manages the full trip lifecycle: initiation, pickup arrival,
 * trip start/end, distance calculations, and near-pickup detection.
 *
 * @see Requirements 2.4, 5.1–5.4, 6.1–6.5, 8.1–8.5
 */
class TripTrackingService
{
    private const EARTH_RADIUS_KM = 6371;
    private const NEAR_PICKUP_THRESHOLD_METERS = 200;

    public function __construct(
        private WaitingTimeService $waitingTimeService,
        private BookingLifecycleService $bookingLifecycleService
    ) {}

    /**
     * Initiate trip tracking by linking session to assignment.
     */
    public function initiateTripTracking(DriverAssignment $assignment, DriverSession $session): void
    {
        $session->update(['assignment_id' => $assignment->id]);
        $assignment->update(['trip_phase' => TripPhase::ACCEPTED]);
    }

    /**
     * Get current trip status with all tracking details.
     */
    public function getTripStatus(DriverAssignment $assignment): array
    {
        $assignment->loadMissing(['bookingItem', 'driver', 'stops']);
        $stops = $this->ensureAssignmentStops($assignment);

        $bookingItem = $assignment->bookingItem;
        $driver = $assignment->driver;
        $isOpenPackage = $this->isOpenPackageAssignment($assignment);

        $pickupLocation = null;
        $distanceToPickup = null;
        $nearPickup = false;

        if ($bookingItem) {
            $pickupLocation = [
                'latitude' => $bookingItem->pickup_latitude ? (float) $bookingItem->pickup_latitude : null,
                'longitude' => $bookingItem->pickup_longitude ? (float) $bookingItem->pickup_longitude : null,
                'landmark' => $bookingItem->pickup_landmark ?? null,
            ];

            if ($driver && $assignment->trip_phase === TripPhase::ACCEPTED) {
                $distanceToPickup = $this->calculateDistanceToPickup($driver, $bookingItem);
                $nearPickup = $this->isNearPickup($driver, $bookingItem);
            }
        }

        $pickupArrival = null;
        if ($assignment->pickup_arrived_at) {
            $pickupArrival = [
                'arrived_at' => $assignment->pickup_arrived_at->toIso8601String(),
                'latitude' => $assignment->pickup_arrival_latitude ? (float) $assignment->pickup_arrival_latitude : null,
                'longitude' => $assignment->pickup_arrival_longitude ? (float) $assignment->pickup_arrival_longitude : null,
            ];
        }

        $waitingTime = $this->waitingTimeService->getTotalWaitingTime($assignment);

        return [
            'assignment_id' => $assignment->id,
            'booking_id' => $assignment->booking_id,
            'trip_phase' => $assignment->trip_phase->value,
            'trip_mode' => $isOpenPackage ? 'open_package' : 'fixed_route',
            'destination_known' => !$isOpenPackage,
            'driver_message' => $isOpenPackage ? 'Open chauffeur package - destination decided during trip' : null,
            'package' => $this->mapPackageForMobile($bookingItem),
            'is_multi_stop' => $this->isMultiStopAssignment($assignment, $stops),
            'pickup_location' => $pickupLocation,
            'pickup_arrival' => $pickupArrival,
            'trip_started_at' => $assignment->trip_started_at?->toIso8601String(),
            'stops' => $this->mapStopsForMobile($stops),
            'current_stop' => $this->mapStopForMobile($this->resolveCurrentStop($stops)),
            'allowed_actions' => $this->getAssignmentAllowedActions($assignment, $stops),
            'estimated_distance_to_pickup_km' => $distanceToPickup,
            'near_pickup' => $nearPickup,
            'cumulative_distance_km' => $this->calculateTripDistance($assignment),
            'total_waiting_time_seconds' => $waitingTime['total_waiting_time_seconds'],
            'waiting_period_count' => $waitingTime['waiting_period_count'],
            'route_point_count' => $assignment->routePoints()->count(),
        ];
    }

    /**
     * Confirm arrival at pickup location.
     *
     * @throws \InvalidArgumentException
     */
    public function confirmPickupArrival(DriverAssignment $assignment, array $coordinates): void
    {
        // Idempotent: already in target state means the transition succeeded on a prior attempt
        if ($assignment->trip_phase === TripPhase::PICKUP_ARRIVED) {
            return;
        }

        if ($assignment->trip_phase !== TripPhase::ACCEPTED) {
            throw new \InvalidArgumentException('TRIP_PICKUP_NOT_CONFIRMED');
        }

        $assignment->update([
            'trip_phase' => TripPhase::PICKUP_ARRIVED,
            'pickup_arrived_at' => Carbon::now('UTC'),
            'pickup_arrival_latitude' => $coordinates['latitude'],
            'pickup_arrival_longitude' => $coordinates['longitude'],
        ]);
    }

    /**
     * Start the trip after pickup.
     *
     * @throws \InvalidArgumentException
     */
    public function startTrip(DriverAssignment $assignment): void
    {
        // Idempotent: already in progress means this transition succeeded on a prior attempt
        if ($assignment->trip_phase === TripPhase::IN_PROGRESS) {
            return;
        }

        $stops = $this->ensureAssignmentStops($assignment);

        if (
            $assignment->trip_phase !== TripPhase::PICKUP_ARRIVED
            && !($this->isMultiStopAssignment($assignment, $stops) && $assignment->trip_phase === TripPhase::ACCEPTED)
        ) {
            throw new \InvalidArgumentException('TRIP_PICKUP_NOT_CONFIRMED');
        }

        $now = Carbon::now('UTC');

        $assignment->update([
            'trip_phase' => TripPhase::IN_PROGRESS,
            'trip_started_at' => $now,
            'actual_start' => $now,
        ]);

        if ($this->isMultiStopAssignment($assignment, $stops)) {
            $this->markStartingPickupCompleted($stops);
        }

        // Keep dispatch state aligned once the trip actually starts.
        $assignment->loadMissing('booking.dispatch');
        $dispatch = $assignment->booking?->dispatch;
        if ($dispatch && $dispatch->dispatch_status === DispatchStatus::DISPATCHED) {
            $dispatch->update(['dispatch_status' => DispatchStatus::IN_PROGRESS]);
        }
    }

    /**
     * End the trip, record final state, close waiting records, return summary.
     *
     * @throws \InvalidArgumentException
     */
    public function endTrip(DriverAssignment $assignment, array $finalLocation): array
    {
        // Idempotent: already completed — return the stored summary rather than re-processing
        if ($assignment->trip_phase === TripPhase::COMPLETED) {
            $waitingTime = $this->waitingTimeService->getTotalWaitingTime($assignment);
            return [
                'assignment_id'              => $assignment->id,
                'booking_id'                 => $assignment->booking_id,
                'booking_item_id'            => $assignment->booking_item_id,
                'total_distance_km'          => round((float) $assignment->total_distance_km, 2),
                'total_duration_minutes'     => $assignment->trip_started_at && $assignment->trip_completed_at
                    ? (int) $assignment->trip_started_at->diffInMinutes($assignment->trip_completed_at)
                    : 0,
                'total_waiting_time_seconds' => $waitingTime['total_waiting_time_seconds'],
                'waiting_period_count'       => $waitingTime['waiting_period_count'],
                'pickup_coordinates'         => [
                    'latitude'  => $assignment->pickup_arrival_latitude  ? (float) $assignment->pickup_arrival_latitude  : null,
                    'longitude' => $assignment->pickup_arrival_longitude ? (float) $assignment->pickup_arrival_longitude : null,
                ],
                'dropoff_coordinates'        => [
                    'latitude'  => (float) ($finalLocation['latitude']  ?? $assignment->final_latitude),
                    'longitude' => (float) ($finalLocation['longitude'] ?? $assignment->final_longitude),
                ],
                'route_point_count'          => $assignment->routePoints()->count(),
                'hire_completed'             => true,
                'payment'                    => $this->mapBookingPaymentSummary($assignment->booking),
            ];
        }

        if ($assignment->trip_phase !== TripPhase::IN_PROGRESS) {
            throw new \InvalidArgumentException('TRIP_NOT_IN_PROGRESS');
        }

        $stops = $this->ensureAssignmentStops($assignment);
        if ($this->isMultiStopAssignment($assignment, $stops) && !$this->allStopsTerminal($stops)) {
            throw new \InvalidArgumentException('TRIP_STOPS_INCOMPLETE');
        }

        return DB::transaction(function () use ($assignment, $finalLocation) {
            $now = Carbon::now('UTC');

            // Close open waiting records
            $this->waitingTimeService->closeOpenWaitingRecords($assignment);

            $totalDistance = $this->calculateTripDistance($assignment);
            $waitingTime = $this->waitingTimeService->getTotalWaitingTime($assignment);

            $durationMinutes = $assignment->trip_started_at
                ? (int) $assignment->trip_started_at->diffInMinutes($now)
                : 0;

            $assignment->update([
                'trip_phase' => TripPhase::COMPLETED,
                'status' => 'completed',
                'trip_completed_at' => $now,
                'actual_end' => $now,
                'final_latitude' => $finalLocation['latitude'],
                'final_longitude' => $finalLocation['longitude'],
                'total_distance_km' => $totalDistance,
                'total_waiting_time_seconds' => $waitingTime['total_waiting_time_seconds'],
            ]);

            // Disassociate session from assignment
            $driver = $assignment->driver;
            if ($driver) {
                $session = $driver->activeSession;
                if ($session && $session->assignment_id === $assignment->id) {
                    $session->update(['assignment_id' => null]);
                }
            }

            // Persist telemetry first. BookingLifecycleService remains the single
            // owner of final charges and invoices only after using these metrics.
            $this->recordOpenPackageTripCompletionMetrics(
                $assignment,
                $finalLocation,
                $totalDistance,
                $durationMinutes,
                $waitingTime,
                $now
            );
            $packageCharges = $this->syncBookingLifecycleAfterDriverTripCompletion($assignment, $finalLocation, $now);
            $paymentSummary = $this->syncTripEndPaymentCollection($assignment, $finalLocation, $now);

            return [
                'assignment_id' => $assignment->id,
                'booking_id' => $assignment->booking_id,
                'booking_item_id' => $assignment->booking_item_id,
                'total_distance_km' => round($totalDistance, 2),
                'total_duration_minutes' => $durationMinutes,
                'total_waiting_time_seconds' => $waitingTime['total_waiting_time_seconds'],
                'waiting_period_count' => $waitingTime['waiting_period_count'],
                'pickup_coordinates' => [
                    'latitude' => $assignment->pickup_arrival_latitude ? (float) $assignment->pickup_arrival_latitude : null,
                    'longitude' => $assignment->pickup_arrival_longitude ? (float) $assignment->pickup_arrival_longitude : null,
                ],
                'dropoff_coordinates' => [
                    'latitude' => (float) $finalLocation['latitude'],
                    'longitude' => (float) $finalLocation['longitude'],
                ],
                'route_point_count' => $assignment->routePoints()->count(),
                'hire_completed' => true,
                'package_charges' => $packageCharges,
                'payment' => $paymentSummary,
            ];
        });
    }

    public function markStopArrived(DriverAssignment $assignment, DriverAssignmentStop $stop, array $data): array
    {
        $this->assertStopBelongsToAssignment($assignment, $stop);

        if ($assignment->trip_phase !== TripPhase::IN_PROGRESS) {
            throw new \InvalidArgumentException('TRIP_NOT_IN_PROGRESS');
        }

        // Idempotent: already arrived at this stop
        if ($stop->status === 'arrived') {
            return ['stop_id' => $stop->id, 'status' => $stop->status];
        }

        if ($stop->isTerminal()) {
            throw new \InvalidArgumentException('STOP_ALREADY_COMPLETED');
        }

        if ($stop->status !== 'pending') {
            throw new \InvalidArgumentException('STOP_INVALID_STATE');
        }

        if (!$this->isNextActionableStop($assignment, $stop)) {
            throw new \InvalidArgumentException('STOP_OUT_OF_SEQUENCE');
        }

        $stop->update([
            'status' => 'arrived',
            'arrived_at' => Carbon::now('UTC'),
            'arrived_latitude' => $data['latitude'],
            'arrived_longitude' => $data['longitude'],
            'notes' => $data['notes'] ?? $stop->notes,
        ]);

        return $this->getTripStatus($assignment->fresh());
    }

    public function completePickupStop(DriverAssignment $assignment, DriverAssignmentStop $stop, array $data): array
    {
        return $this->completeStop($assignment, $stop, $data, 'pickup', 'picked_up');
    }

    public function completeDropoffStop(DriverAssignment $assignment, DriverAssignmentStop $stop, array $data): array
    {
        return $this->completeStop($assignment, $stop, $data, 'dropoff', 'dropped_off');
    }

    public function skipStop(DriverAssignment $assignment, DriverAssignmentStop $stop, array $data): array
    {
        $this->assertStopBelongsToAssignment($assignment, $stop);

        if ($assignment->trip_phase !== TripPhase::IN_PROGRESS) {
            throw new \InvalidArgumentException('TRIP_NOT_IN_PROGRESS');
        }

        if ($stop->isTerminal()) {
            throw new \InvalidArgumentException('STOP_ALREADY_COMPLETED');
        }

        if (!in_array($stop->status, ['pending', 'arrived'], true)) {
            throw new \InvalidArgumentException('STOP_INVALID_STATE');
        }

        if (!$this->isNextActionableStop($assignment, $stop)) {
            throw new \InvalidArgumentException('STOP_OUT_OF_SEQUENCE');
        }

        $stop->update([
            'status' => 'skipped',
            'completed_at' => Carbon::now('UTC'),
            'completed_latitude' => $data['latitude'] ?? null,
            'completed_longitude' => $data['longitude'] ?? null,
            'completed_action' => 'skipped',
            'skip_reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? $stop->notes,
        ]);

        return $this->getTripStatus($assignment->fresh());
    }

    public function ensureAssignmentStops(DriverAssignment $assignment): Collection
    {
        if ($this->isOpenPackageAssignment($assignment)) {
            return collect();
        }

        if ($assignment->stops()->exists()) {
            $stops = $assignment->stops()->get();
            $this->backfillStopIdentifiers($assignment, $stops);
            return $assignment->stops()->get();
        }

        $assignment->loadMissing('bookingItem');
        $bookingItem = $assignment->bookingItem;
        if (!$bookingItem) {
            return collect();
        }

        $routeStops = $this->buildRouteStopsFromBookingItem($bookingItem);
        if (empty($routeStops)) {
            return collect();
        }

        return DB::transaction(function () use ($assignment, $bookingItem, $routeStops) {
            foreach ($routeStops as $index => $routeStop) {
                DriverAssignmentStop::create([
                    'assignment_id' => $assignment->id,
                    'booking_id' => $assignment->booking_id,
                    'booking_item_id' => $bookingItem->id,
                    'booking_stop_id' => $routeStop['booking_stop_id'],
                    'stop_type' => $routeStop['stop_type'],
                    'route_order' => $index + 1,
                    'type_sequence' => $routeStop['type_sequence'],
                    'status' => 'pending',
                    'location' => $routeStop['location'],
                    'label' => $routeStop['label'],
                    'address' => $routeStop['address'],
                    'latitude' => $routeStop['latitude'],
                    'longitude' => $routeStop['longitude'],
                ]);
            }

            return $assignment->stops()->get();
        });
    }

    private function completeStop(
        DriverAssignment $assignment,
        DriverAssignmentStop $stop,
        array $data,
        string $expectedType,
        string $completedStatus
    ): array {
        $this->assertStopBelongsToAssignment($assignment, $stop);

        if ($assignment->trip_phase !== TripPhase::IN_PROGRESS) {
            throw new \InvalidArgumentException('TRIP_NOT_IN_PROGRESS');
        }

        if ($stop->stop_type !== $expectedType) {
            throw new \InvalidArgumentException('STOP_TYPE_MISMATCH');
        }

        if ($stop->status !== 'arrived') {
            throw new \InvalidArgumentException('STOP_ARRIVAL_REQUIRED');
        }

        if (!$this->isNextActionableStop($assignment, $stop)) {
            throw new \InvalidArgumentException('STOP_OUT_OF_SEQUENCE');
        }

        $stop->update([
            'status' => $completedStatus,
            'completed_at' => Carbon::now('UTC'),
            'completed_latitude' => $data['latitude'] ?? null,
            'completed_longitude' => $data['longitude'] ?? null,
            'completed_action' => $completedStatus,
            'notes' => $data['notes'] ?? $stop->notes,
        ]);

        return $this->getTripStatus($assignment->fresh());
    }

    private function assertStopBelongsToAssignment(DriverAssignment $assignment, DriverAssignmentStop $stop): void
    {
        if ((string) $stop->assignment_id !== (string) $assignment->id) {
            throw new \InvalidArgumentException('STOP_NOT_FOUND');
        }
    }

    private function isNextActionableStop(DriverAssignment $assignment, DriverAssignmentStop $stop): bool
    {
        $stops = $assignment->stops()->get();
        foreach ($stops as $candidate) {
            if ((string) $candidate->id === (string) $stop->id) {
                return true;
            }
            if (!$candidate->isTerminal()) {
                return false;
            }
        }

        return false;
    }

    private function resolveCurrentStop(Collection $stops): ?DriverAssignmentStop
    {
        return $stops->first(fn (DriverAssignmentStop $stop) => !$stop->isTerminal());
    }

    private function markStartingPickupCompleted(Collection $stops): void
    {
        $firstStop = $stops->sortBy('route_order')->first();
        if (!$firstStop || $firstStop->stop_type !== 'pickup' || $firstStop->isTerminal()) {
            return;
        }

        $now = Carbon::now('UTC');
        $firstStop->update([
            'status' => 'picked_up',
            'arrived_at' => $firstStop->arrived_at ?? $now,
            'completed_at' => $now,
            'completed_action' => 'picked_up',
            'notes' => $firstStop->notes ?? 'Completed when hire was started',
        ]);
    }

    private function allStopsTerminal(Collection $stops): bool
    {
        return $stops->isNotEmpty() && $stops->every(fn (DriverAssignmentStop $stop) => $stop->isTerminal());
    }

    private function isMultiStopAssignment(DriverAssignment $assignment, ?Collection $stops = null): bool
    {
        $stops = $stops ?? $assignment->stops()->get();
        return $stops->count() > 2;
    }

    public function getAssignmentAllowedActions(DriverAssignment $assignment, ?Collection $stops = null): array
    {
        $stops ??= $this->ensureAssignmentStops($assignment);

        if (in_array($assignment->trip_phase, [TripPhase::ACTIVE, TripPhase::CONFIRMED], true)) {
            return ['accept', 'decline'];
        }

        if ($this->isOpenPackageAssignment($assignment)) {
            return match ($assignment->trip_phase) {
                TripPhase::ACCEPTED => ['arrived'],
                TripPhase::PICKUP_ARRIVED => ['start'],
                TripPhase::IN_PROGRESS => ['complete'],
                default => [],
            };
        }

        if ($assignment->trip_phase === TripPhase::ACCEPTED) {
            $actions = ['arrived'];
            if ($this->isMultiStopAssignment($assignment, $stops)) {
                $actions[] = 'start';
            }
            return $actions;
        }

        if ($assignment->trip_phase === TripPhase::PICKUP_ARRIVED) {
            return ['start'];
        }

        if ($assignment->trip_phase === TripPhase::IN_PROGRESS) {
            return $stops->isEmpty() || $this->allStopsTerminal($stops) ? ['complete'] : ['stop_action'];
        }

        return [];
    }

    public function mapStopsForMobile(Collection $stops): array
    {
        $typeCounters = [];

        return $stops
            ->sortBy('route_order')
            ->map(function (DriverAssignmentStop $stop) use (&$typeCounters) {
                $type = (string) $stop->stop_type;
                $typeCounters[$type] = ($typeCounters[$type] ?? 0) + 1;

                return $this->mapStopForMobile($stop, $typeCounters[$type]);
            })
            ->values()
            ->all();
    }

    private function mapStopForMobile(?DriverAssignmentStop $stop, ?int $fallbackTypeSequence = null): ?array
    {
        if (!$stop) {
            return null;
        }

        $typeSequence = $stop->type_sequence ?? $fallbackTypeSequence;
        $displayLabel = $stop->label ?: $this->formatStopLabel($stop->stop_type, $typeSequence);

        return [
            'id' => $stop->id,
            'booking_stop_id' => $stop->booking_stop_id,
            'type' => $stop->stop_type,
            'type_sequence' => $typeSequence,
            'route_order' => $stop->route_order,
            'status' => $stop->status,
            'label' => $displayLabel,
            'display_label' => $displayLabel,
            'address' => $stop->address,
            'latitude' => $stop->latitude !== null ? (float) $stop->latitude : null,
            'longitude' => $stop->longitude !== null ? (float) $stop->longitude : null,
            'contact' => [
                'employee_id' => $stop->location['employee_id'] ?? null,
                'contact_name' => $stop->location['contact_name'] ?? null,
                'contact_phone' => $stop->location['contact_phone'] ?? null,
                'contact_note' => $stop->location['contact_note'] ?? null,
            ],
            'arrived_at' => $stop->arrived_at?->toIso8601String(),
            'arrived_latitude' => $stop->arrived_latitude !== null ? (float) $stop->arrived_latitude : null,
            'arrived_longitude' => $stop->arrived_longitude !== null ? (float) $stop->arrived_longitude : null,
            'completed_at' => $stop->completed_at?->toIso8601String(),
            'completed_latitude' => $stop->completed_latitude !== null ? (float) $stop->completed_latitude : null,
            'completed_longitude' => $stop->completed_longitude !== null ? (float) $stop->completed_longitude : null,
            'completed_action' => $stop->completed_action,
            'skip_reason' => $stop->skip_reason,
            'notes' => $stop->notes,
            'allowed_actions' => $this->resolveStopAllowedActions($stop),
        ];
    }

    private function resolveStopAllowedActions(DriverAssignmentStop $stop): array
    {
        if ($stop->isTerminal()) {
            return [];
        }

        if ($stop->status === 'pending') {
            return ['arrived', 'skip'];
        }

        if ($stop->status === 'arrived') {
            return [
                $stop->stop_type === 'pickup' ? 'picked_up' : 'dropped_off',
                'skip',
            ];
        }

        return [];
    }

    private function buildRouteStopsFromBookingItem(BookingItem $bookingItem): array
    {
        if ($this->isOpenPackageBookingItem($bookingItem)) {
            return [];
        }

        $metadata = is_array($bookingItem->metadata) ? $bookingItem->metadata : [];

        $routeStops = [];
        $routeStops[] = $this->makeRouteStop(
            'pickup',
            $bookingItem->pickup_location,
            null,
            $bookingItem->pickup_latitude,
            $bookingItem->pickup_longitude,
            'primary-pickup'
        );

        $orderedStops = $this->normalizeArrayPayload($metadata['multi_route_stop_order'] ?? []);
        if (!empty($orderedStops)) {
            foreach ($orderedStops as $stop) {
                $type = strtolower((string) ($stop['type'] ?? $stop['stop_type'] ?? ''));
                if (!in_array($type, ['pickup', 'dropoff'], true)) {
                    continue;
                }

                $routeStops[] = $this->makeRouteStop(
                    $type,
                    array_merge(
                        is_array($stop['location'] ?? null) ? $stop['location'] : $stop,
                        array_intersect_key($stop, array_flip(['employee_id', 'contact_name', 'contact_phone', 'contact_note']))
                    ),
                    null,
                    null,
                    null,
                    $stop['stop_id'] ?? $stop['stopId'] ?? null
                );
            }
        } else {
            foreach ($this->normalizeArrayPayload($metadata['multi_pickup_locations'] ?? []) as $stop) {
                $routeStops[] = $this->makeRouteStop(
                    'pickup',
                    array_merge(
                        is_array($stop['location'] ?? null) ? $stop['location'] : $stop,
                        array_intersect_key($stop, array_flip(['employee_id', 'contact_name', 'contact_phone', 'contact_note']))
                    ),
                    null,
                    null,
                    null,
                    $stop['stop_id'] ?? $stop['stopId'] ?? null
                );
            }

            foreach ($this->normalizeArrayPayload($metadata['multi_dropoff_locations'] ?? []) as $stop) {
                $routeStops[] = $this->makeRouteStop(
                    'dropoff',
                    array_merge(
                        is_array($stop['location'] ?? null) ? $stop['location'] : $stop,
                        array_intersect_key($stop, array_flip(['employee_id', 'contact_name', 'contact_phone', 'contact_note']))
                    ),
                    null,
                    null,
                    null,
                    $stop['stop_id'] ?? $stop['stopId'] ?? null
                );
            }
        }

        $routeStops[] = $this->makeRouteStop(
            'dropoff',
            $bookingItem->dropoff_location,
            null,
            $bookingItem->dropoff_latitude,
            $bookingItem->dropoff_longitude,
            'primary-dropoff'
        );

        return $this->prepareRouteStopsForPersistence($bookingItem, array_values(array_filter($routeStops)));
    }

    private function makeRouteStop(
        string $type,
        mixed $location,
        ?string $fallbackLabel,
        mixed $fallbackLatitude = null,
        mixed $fallbackLongitude = null,
        ?string $sourceStopId = null
    ): ?array
    {
        $normalized = $this->normalizeLocation($location);
        if (!$normalized) {
            return null;
        }

        return [
            'stop_type' => $type,
            'location' => $normalized,
            'label' => $normalized['label'] ?? $normalized['name'] ?? $fallbackLabel,
            'address' => $normalized['address'] ?? $normalized['formatted_address'] ?? null,
            'latitude' => $this->toNullableFloat($normalized['latitude'] ?? $normalized['lat'] ?? $fallbackLatitude),
            'longitude' => $this->toNullableFloat($normalized['longitude'] ?? $normalized['lng'] ?? $fallbackLongitude),
            'source_stop_id' => $sourceStopId
                ?? $normalized['stop_id']
                ?? $normalized['stopId']
                ?? null,
        ];
    }

    private function prepareRouteStopsForPersistence(BookingItem $bookingItem, array $routeStops): array
    {
        $typeCounters = [];

        foreach ($routeStops as $index => $routeStop) {
            $type = (string) $routeStop['stop_type'];
            $typeCounters[$type] = ($typeCounters[$type] ?? 0) + 1;
            $typeSequence = $typeCounters[$type];
            $routeOrder = $index + 1;

            $routeStops[$index]['type_sequence'] = $typeSequence;
            $routeStops[$index]['label'] = $routeStop['label'] ?: $this->formatStopLabel($type, $typeSequence);
            $routeStops[$index]['booking_stop_id'] = $this->buildBookingStopId(
                $bookingItem,
                $type,
                $routeOrder,
                $typeSequence,
                $routeStop['source_stop_id'] ?? null
            );
        }

        return $routeStops;
    }

    private function backfillStopIdentifiers(DriverAssignment $assignment, Collection $stops): void
    {
        $bookingItemId = $assignment->booking_item_id;
        $typeCounters = [];

        foreach ($stops->sortBy('route_order') as $stop) {
            $type = (string) $stop->stop_type;
            $typeCounters[$type] = ($typeCounters[$type] ?? 0) + 1;
            $typeSequence = $stop->type_sequence ?: $typeCounters[$type];
            $label = $stop->label ?: $this->formatStopLabel($type, $typeSequence);
            $bookingStopId = $stop->booking_stop_id ?: sprintf(
                'booking-item:%s:%s:%03d',
                $bookingItemId ?: $assignment->booking_id,
                $type,
                (int) $stop->route_order
            );

            if (
                $stop->type_sequence !== $typeSequence ||
                $stop->label !== $label ||
                $stop->booking_stop_id !== $bookingStopId
            ) {
                $stop->forceFill([
                    'type_sequence' => $typeSequence,
                    'label' => $label,
                    'booking_stop_id' => $bookingStopId,
                ])->save();
            }
        }
    }

    private function buildBookingStopId(
        BookingItem $bookingItem,
        string $type,
        int $routeOrder,
        int $typeSequence,
        ?string $sourceStopId = null
    ): string {
        if ($sourceStopId) {
            return sprintf('booking-item:%s:%s', $bookingItem->id, $sourceStopId);
        }

        return sprintf(
            'booking-item:%s:%s:%03d:%03d',
            $bookingItem->id,
            $type,
            $typeSequence,
            $routeOrder
        );
    }

    private function formatStopLabel(string $type, ?int $typeSequence): string
    {
        $prefix = $type === 'pickup' ? 'Pickup' : 'Drop-off';
        return $typeSequence ? "{$prefix} {$typeSequence}" : $prefix;
    }

    private function normalizeLocation(mixed $location): ?array
    {
        if (is_string($location)) {
            $decoded = json_decode($location, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $location = $decoded;
            } elseif (trim($location) !== '') {
                $location = ['address' => $location];
            }
        }

        if (!is_array($location)) {
            return null;
        }

        return $location;
    }

    private function normalizeArrayPayload(mixed $payload): array
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        return is_array($payload) ? $payload : [];
    }

    private function toNullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Driver-completed hire should finish lifecycle and immediately release vehicle
     * without forcing QC/Maintenance flow.
     */
    private function syncBookingLifecycleAfterDriverTripCompletion(
        DriverAssignment $assignment,
        array $finalLocation,
        Carbon $completedAt
    ): ?array {
        if (!$assignment->booking_id) {
            return null;
        }

        $assignment->loadMissing(['booking.dispatch', 'bookingItem']);
        $booking = $assignment->booking;
        if (!$booking) {
            return null;
        }

        // Primary path: use lifecycle service so dispatch + booking tracking stay consistent.
        try {
            if ($booking->dispatch) {
                $this->bookingLifecycleService->processReturn((string) $booking->id, [
                    'booking_item_id' => $assignment->booking_item_id,
                    'actual_return_time' => $completedAt->toIso8601String(),
                    'mileage' => $finalLocation['ending_mileage'] ?? null,
                    'notes' => $finalLocation['notes'] ?? 'Completed via driver mobile app',
                    'condition' => null,
                    'damages' => [],
                    'charges' => [],
                    'completed_by_driver' => true,
                    'skip_qc' => true,
                ]);
                return $this->resolveCanonicalFinalPricingSummary($assignment);
            }
        } catch (\Throwable $exception) {
            Log::warning('Driver trip completion fallback: lifecycle return processing failed', [
                'assignment_id' => $assignment->id,
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
            ]);
        }

        // Dispatch-less assignments still use the canonical completion service so
        // final mobile metrics are priced and invoiced before the legacy fallback.
        try {
            $this->bookingLifecycleService->completeBooking(
                (string) $booking->id,
                [
                    'activity_source' => 'driver_mobile',
                    'actual_start_time' => $assignment->trip_started_at?->toIso8601String(),
                    'actual_return_time' => $completedAt->toIso8601String(),
                    'actual_distance' => (float) ($assignment->total_distance_km ?? 0),
                    'waiting_minutes' => (int) ceil(((int) $assignment->total_waiting_time_seconds) / 60),
                    'completed_by_driver' => true,
                ],
                $assignment->booking_item_id
            );
            return $this->resolveCanonicalFinalPricingSummary($assignment);
        } catch (\Throwable $exception) {
            Log::error('Driver trip completion blocked: canonical completion failed', [
                'assignment_id' => $assignment->id,
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
            ]);

            throw new \DomainException(
                'Driver trip completion was stopped because canonical final pricing or invoicing could not be completed: '
                . $exception->getMessage(),
                previous: $exception
            );
        }
    }

    private function resolveCanonicalFinalPricingSummary(DriverAssignment $assignment): ?array
    {
        if (!$this->isOpenPackageAssignment($assignment) || !$assignment->booking_item_id) {
            return null;
        }

        $bookingItem = BookingItem::find($assignment->booking_item_id);
        if (!$bookingItem) {
            return null;
        }

        $summary = data_get($bookingItem->pricing_breakdown, 'final_pricing.audit')
            ?? data_get($bookingItem->metadata, 'final_pricing_audit');

        return is_array($summary) ? $summary : null;
    }

    private function syncTripEndPaymentCollection(DriverAssignment $assignment, array $finalLocation, Carbon $completedAt): ?array
    {
        $assignment->loadMissing('booking');
        $booking = $assignment->booking?->fresh();

        if (!$booking) {
            return null;
        }

        if ($this->bookingRequiresDriverCollection($booking)) {
            $fareAmount = $this->resolveBookingFareAmount($booking);

            $booking->update([
                'payment_collection_method' => 'cash_to_driver',
                'payment_collection_status' => $booking->payment_collection_status === 'driver_collected'
                    ? 'driver_collected'
                    : 'pending_collection',
                'payment_method' => 'cash_to_driver',
                'payment_type' => 'cash',
                'amount_to_pay' => $fareAmount,
                'payment_status' => $booking->payment_collection_status === 'driver_collected'
                    ? $booking->payment_status
                    : 'pending',
            ]);

            return $this->mapBookingPaymentSummary($booking->fresh());
        }

        if ($this->bookingUsesMonthlyInvoice($booking) && $booking->payment_collection_status !== 'invoiced') {
            $booking->update([
                'amount_to_pay' => $this->resolveBookingFareAmount($booking),
                'payment_responsibility' => $booking->payment_responsibility ?: 'corporate',
                'payment_collection_method' => 'monthly_invoice',
                'payment_collection_status' => 'billable',
                'payment_method' => 'monthly_invoice',
                'payment_type' => 'corporate',
                'payment_status' => 'pending',
            ]);
            return $this->mapBookingPaymentSummary($booking->fresh());
        }

        $fareAmount = $this->resolveBookingFareAmount($booking);
        $paidStatuses = ['paid', 'online_paid', 'driver_collected'];
        if (!in_array((string) $booking->payment_collection_status, $paidStatuses, true)
            && !in_array((string) $booking->payment_status, ['paid', 'refunded'], true)) {
            $booking->update([
                'amount_to_pay' => $fareAmount,
                'payment_collection_method' => $booking->payment_collection_method ?: 'online',
                'payment_collection_status' => 'payment_pending',
                'payment_method' => $booking->payment_method ?: 'online',
                'payment_type' => $booking->payment_type ?: 'online',
                'payment_status' => 'pending',
            ]);

            return $this->mapBookingPaymentSummary($booking->fresh());
        }

        return $this->mapBookingPaymentSummary($booking);
    }

    public function collectPayment(DriverAssignment $assignment, array $paymentData): array
    {
        $assignment->loadMissing('booking');
        $booking = $assignment->booking?->fresh();

        if (!$booking) {
            throw new \InvalidArgumentException('PAYMENT_BOOKING_NOT_FOUND');
        }

        if (!$this->bookingRequiresDriverCollection($booking)) {
            throw new \InvalidArgumentException('PAYMENT_COLLECTION_NOT_REQUIRED');
        }

        $collectedAmount = round((float) $paymentData['collected_amount'], 2);
        $fareAmount = $this->resolveBookingFareAmount($booking);
        $now = Carbon::now('UTC');

        $booking->update([
            'payment_collected_amount' => $collectedAmount,
            'payment_collected_at' => $now,
            'payment_collected_by_driver_id' => $assignment->driver_id,
            'payment_notes' => $paymentData['payment_notes'] ?? null,
            'payment_collection_method' => 'cash_to_driver',
            'payment_collection_status' => 'driver_collected',
            'payment_method' => 'cash_to_driver',
            'payment_type' => 'cash',
            'amount_to_pay' => $fareAmount,
            'payment_status' => $collectedAmount >= $fareAmount ? 'paid' : 'pending',
        ]);

        return [
            'assignment_id' => $assignment->id,
            'booking_id' => $assignment->booking_id,
            'payment' => $this->mapBookingPaymentSummary($booking->fresh()),
        ];
    }

    private function bookingRequiresDriverCollection($booking): bool
    {
        $method = strtolower((string) ($booking->payment_collection_method ?? $booking->payment_method ?? $booking->payment_type ?? ''));
        return in_array($method, ['cash_to_driver', 'cash', 'driver_cash', 'pay_to_driver'], true);
    }

    private function bookingUsesMonthlyInvoice($booking): bool
    {
        $method = strtolower((string) ($booking->payment_collection_method ?? $booking->payment_method ?? $booking->payment_type ?? ''));
        return in_array($method, ['monthly_invoice', 'corporate', 'company_billing', 'credit'], true) || str_contains($method, 'corp');
    }

    private function resolveBookingFareAmount($booking): float
    {
        return round((float) ($booking->total_actual ?? $booking->total_estimated ?? 0), 2);
    }

    private function mapBookingPaymentSummary($booking): ?array
    {
        if (!$booking) {
            return null;
        }

        return [
            'payment_responsibility' => $booking->payment_responsibility,
            'payment_collection_method' => $booking->payment_collection_method,
            'payment_collection_status' => $booking->payment_collection_status,
            'payment_status' => $booking->payment_status,
            'final_amount' => $this->resolveBookingFareAmount($booking),
            'amount_to_pay' => $booking->amount_to_pay !== null ? (float) $booking->amount_to_pay : $this->resolveBookingFareAmount($booking),
            'currency' => $booking->currency,
            'collection_required' => $this->bookingRequiresDriverCollection($booking)
                && $booking->payment_collection_status !== 'driver_collected',
            'collection_message' => $this->resolvePaymentCollectionMessage($booking),
            'amount_to_collect' => $this->bookingRequiresDriverCollection($booking)
                ? $this->resolveBookingFareAmount($booking)
                : null,
            'payment_collected_amount' => $booking->payment_collected_amount !== null ? (float) $booking->payment_collected_amount : null,
            'payment_collected_at' => $booking->payment_collected_at?->toIso8601String(),
            'payment_collected_by_driver_id' => $booking->payment_collected_by_driver_id,
            'payment_notes' => $booking->payment_notes,
        ];
    }

    private function resolvePaymentCollectionMessage($booking): string
    {
        if ($this->bookingRequiresDriverCollection($booking)) {
            return $booking->payment_collection_status === 'driver_collected'
                ? 'Cash collected by driver'
                : 'Collect cash from customer';
        }

        if ($this->bookingUsesMonthlyInvoice($booking)) {
            return 'Do not collect cash. This hire will be added to billing.';
        }

        return 'Do not collect cash. Payment is handled outside driver cash collection.';
    }

    private function isOpenPackageAssignment(DriverAssignment $assignment): bool
    {
        $assignment->loadMissing('bookingItem');
        return $this->isOpenPackageBookingItem($assignment->bookingItem);
    }

    private function isOpenPackageBookingItem(?BookingItem $bookingItem): bool
    {
        $metadata = is_array($bookingItem?->metadata) ? $bookingItem->metadata : [];
        return ($metadata['trip_mode'] ?? null) === 'open_package';
    }

    private function mapPackageForMobile(?BookingItem $bookingItem): ?array
    {
        if (!$bookingItem) {
            return null;
        }

        $metadata = is_array($bookingItem->metadata) ? $bookingItem->metadata : [];
        $packageId = $metadata['service_package_id'] ?? $metadata['package_id'] ?? null;
        if (!$packageId) {
            return null;
        }

        $package = \App\Models\Service\ServicePackage::find($packageId);
        if (!$package) {
            return ['id' => $packageId];
        }

        $includedHours = max(0, (int) ($package->default_duration_hours ?? 0));
        $includedMinuteComponent = min(59, max(0, (int) ($package->default_duration_minutes ?? 0)));

        return [
            'id' => $package->id,
            'name' => $package->name,
            'code' => $package->code,
            'description' => $package->description,
            'included_km_per_day' => $package->max_km_per_day !== null ? (float) $package->max_km_per_day : null,
            'included_km_per_package' => $package->max_km_per_package !== null ? (float) $package->max_km_per_package : null,
            'included_hours' => $includedHours,
            'included_minute_component' => $includedMinuteComponent,
            'included_minutes' => ($includedHours * 60) + $includedMinuteComponent,
            'rate_type' => $package->rate_type,
        ];
    }

    private function recordOpenPackageTripCompletionMetrics(
        DriverAssignment $assignment,
        array $finalLocation,
        float $totalDistance,
        int $durationMinutes,
        array $waitingTime,
        Carbon $completedAt
    ): void {
        $assignment->loadMissing(['booking', 'bookingItem']);
        $bookingItem = $assignment->bookingItem;
        $booking = $assignment->booking;

        if (!$bookingItem || !$booking || !$this->isOpenPackageBookingItem($bookingItem)) {
            return;
        }

        $contractualDistance = $this->hasContractualDistanceSnapshot($booking, $bookingItem);
        $metadata = is_array($bookingItem->metadata) ? $bookingItem->metadata : [];
        $packageInfo = is_array($metadata['package_info'] ?? null) ? $metadata['package_info'] : [];
        $packageId = $metadata['service_package_id'] ?? $metadata['package_id'] ?? null;
        $package = $packageId ? \App\Models\Service\ServicePackage::find($packageId) : null;
        $includedHoursValue = $metadata['included_hours']
            ?? ($packageInfo['default_duration_hours'] ?? $package?->default_duration_hours);
        $includedMinuteComponentValue = $metadata['included_minute_component']
            ?? ($packageInfo['default_duration_minutes'] ?? $package?->default_duration_minutes);

        if (!array_key_exists('included_minutes', $metadata)
            && ($includedHoursValue !== null || $includedMinuteComponentValue !== null)) {
            $includedHours = max(0, (int) ($includedHoursValue ?? 0));
            $includedMinuteComponent = min(59, max(0, (int) ($includedMinuteComponentValue ?? 0)));
            $metadata = array_merge($metadata, [
                'included_hours' => $includedHours,
                'included_minute_component' => $includedMinuteComponent,
                'included_minutes' => ($includedHours * 60) + $includedMinuteComponent,
            ]);
        }

        $waitingMinutes = (int) ceil(($waitingTime['total_waiting_time_seconds'] ?? 0) / 60);
        $finalAddress = $finalLocation['final_address'] ?? $finalLocation['address'] ?? null;
        $finalDropoff = [
            'address' => $finalAddress,
            'latitude' => $finalLocation['latitude'],
            'longitude' => $finalLocation['longitude'],
        ];

        // A contractual snapshot fixes billable coordinates/distance. Keep that
        // immutable while still recording the driver's operational measurements.
        if (!$contractualDistance) {
            $bookingItem->update([
                'dropoff_location' => $finalDropoff,
                'dropoff_latitude' => $finalLocation['latitude'],
                'dropoff_longitude' => $finalLocation['longitude'],
                'metadata' => array_merge($metadata, [
                    'final_dropoff_location' => $finalDropoff,
                    'driver_trip_completed_at' => $completedAt->toIso8601String(),
                ]),
            ]);
        } elseif ($metadata !== $bookingItem->metadata) {
            $bookingItem->update(['metadata' => $metadata]);
        }

        $booking->update([
            'actual_distance' => round($totalDistance, 2),
            'actual_duration' => $durationMinutes,
            'duration_metrics' => array_merge(is_array($booking->duration_metrics) ? $booking->duration_metrics : [], [
                'source' => 'driver_mobile_activity',
                'actual_minutes' => $durationMinutes,
                'waiting_minutes' => $waitingMinutes,
                'recorded_at' => $completedAt->toIso8601String(),
            ]),
            'distance_metrics' => array_merge(is_array($booking->distance_metrics) ? $booking->distance_metrics : [], [
                'source' => 'driver_route_points',
                'actual_km' => round($totalDistance, 2),
                'pricing_effect' => $contractualDistance
                    ? 'none_contractual_snapshot'
                    : 'pending_canonical_final_pricing',
                'recorded_at' => $completedAt->toIso8601String(),
            ]),
        ]);
    }

    private function hasContractualDistanceSnapshot($booking, BookingItem $bookingItem): bool
    {
        return data_get($booking->pricing_snapshot, 'distance_policy.coordinate_source') === 'corporate_distance_policy'
            || data_get($booking->pricing_snapshot, 'base_pricing.distance_policy.coordinate_source') === 'corporate_distance_policy'
            || data_get($bookingItem->pricing_breakdown, 'distance_policy.coordinate_source') === 'corporate_distance_policy'
            || data_get($bookingItem->pricing_breakdown, 'base_pricing.distance_policy.coordinate_source') === 'corporate_distance_policy';
    }

    /**
     * Calculate Haversine distance between driver and pickup in km.
     */
    public function calculateDistanceToPickup(Driver $driver, BookingItem $item): ?float
    {
        if (
            $driver->current_latitude === null || $driver->current_longitude === null ||
            $item->pickup_latitude === null || $item->pickup_longitude === null
        ) {
            return null;
        }

        return $this->haversineDistance(
            (float) $driver->current_latitude,
            (float) $driver->current_longitude,
            (float) $item->pickup_latitude,
            (float) $item->pickup_longitude
        );
    }

    /**
     * Check if driver is within threshold meters of pickup.
     */
    public function isNearPickup(Driver $driver, BookingItem $item, float $thresholdMeters = self::NEAR_PICKUP_THRESHOLD_METERS): bool
    {
        $distanceKm = $this->calculateDistanceToPickup($driver, $item);

        if ($distanceKm === null) {
            return false;
        }

        return ($distanceKm * 1000) <= $thresholdMeters;
    }

    /**
     * Calculate total trip distance from consecutive route points.
     */
    public function calculateTripDistance(DriverAssignment $assignment): float
    {
        $points = $assignment->routePoints()
            ->orderBy('recorded_at', 'asc')
            ->get(['latitude', 'longitude']);

        if ($points->count() < 2) {
            return 0.0;
        }

        $totalDistance = 0.0;

        for ($i = 1; $i < $points->count(); $i++) {
            $totalDistance += $this->haversineDistance(
                (float) $points[$i - 1]->latitude,
                (float) $points[$i - 1]->longitude,
                (float) $points[$i]->latitude,
                (float) $points[$i]->longitude
            );
        }

        return round($totalDistance, 2);
    }

    /**
     * Haversine formula for distance between two coordinates in km.
     */
    private function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_KM * $c;
    }
}
