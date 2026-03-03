<?php

namespace App\Services\Driver;

use App\Enums\TripPhase;
use App\Models\Booking\BookingItem;
use App\Models\Driver\Driver;
use App\Models\Driver\DriverSession;
use App\Models\Driver\RoutePoint;
use App\Models\DriverAssignment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
        private WaitingTimeService $waitingTimeService
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
        $assignment->loadMissing(['bookingItem', 'driver']);

        $bookingItem = $assignment->bookingItem;
        $driver = $assignment->driver;

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
            'pickup_location' => $pickupLocation,
            'pickup_arrival' => $pickupArrival,
            'trip_started_at' => $assignment->trip_started_at?->toIso8601String(),
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
        if ($assignment->trip_phase !== TripPhase::ACCEPTED) {
            throw new \InvalidArgumentException('TRIP_PICKUP_NOT_CONFIRMED');
        }

        $assignment->update([
            'trip_phase' => TripPhase::PICKUP_ARRIVED,
            'pickup_arrived_at' => Carbon::now(),
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
        if ($assignment->trip_phase !== TripPhase::PICKUP_ARRIVED) {
            throw new \InvalidArgumentException('TRIP_PICKUP_NOT_CONFIRMED');
        }

        $assignment->update([
            'trip_phase' => TripPhase::IN_PROGRESS,
            'trip_started_at' => Carbon::now(),
        ]);
    }

    /**
     * End the trip, record final state, close waiting records, return summary.
     *
     * @throws \InvalidArgumentException
     */
    public function endTrip(DriverAssignment $assignment, array $finalLocation): array
    {
        if ($assignment->trip_phase !== TripPhase::IN_PROGRESS) {
            throw new \InvalidArgumentException('TRIP_NOT_IN_PROGRESS');
        }

        return DB::transaction(function () use ($assignment, $finalLocation) {
            $now = Carbon::now();

            // Close open waiting records
            $this->waitingTimeService->closeOpenWaitingRecords($assignment);

            $totalDistance = $this->calculateTripDistance($assignment);
            $waitingTime = $this->waitingTimeService->getTotalWaitingTime($assignment);

            $durationMinutes = $assignment->trip_started_at
                ? (int) $assignment->trip_started_at->diffInMinutes($now)
                : 0;

            $assignment->update([
                'trip_phase' => TripPhase::COMPLETED,
                'trip_completed_at' => $now,
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

            return [
                'assignment_id' => $assignment->id,
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
            ];
        });
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
