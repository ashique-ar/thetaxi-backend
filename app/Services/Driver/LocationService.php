<?php

namespace App\Services\Driver;

use App\Enums\TripPhase;
use App\Models\Driver\Driver;
use App\Models\Driver\DriverSession;
use App\Models\Driver\RoutePoint;
use App\Models\DriverAssignment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Location Service
 * 
 * Handles driver location updates, creating route points for tracking,
 * and updating driver coordinates. Extended to support assignment-aware
 * route points and waiting time detection during active trips.
 * 
 * @see Requirements 4.1, 4.2, 4.4, 4.5, 6.2, 6.3, 6.4, 6.5, 6.6
 */
class LocationService
{
    private const MIN_UPDATE_INTERVAL_SECONDS = 1;

    public function __construct(
        private ?WaitingTimeService $waitingTimeService = null
    ) {}
    /**
     * Update a driver's location and create a route point.
     * 
     * Updates the driver's current coordinates and last_active_at,
     * and creates a RoutePoint record linked to the active session.
     *
     * @param Driver $driver The driver updating their location
     * @param array $locationData Location data including latitude, longitude, and optional fields
     * @return RoutePoint The created route point
     * @throws \Exception If driver has no active session
     * 
     * @see Requirement 6.2 - Update Driver coordinates
     * @see Requirement 6.3 - Update last_active_at
     * @see Requirement 6.4 - Create RoutePoint linked to session
     * @see Requirement 6.5 - Capture all location fields
     * @see Requirement 6.6 - Reject if no active session
     */
    public function updateLocation(Driver $driver, array $locationData): RoutePoint
    {
        // Validate active session exists
        $session = $driver->activeSession;

        if (!$session) {
            throw new \Exception('No active session');
        }

        // Enforce minimum 10-second interval between location updates
        $lastPoint = $session->routePoints()
            ->orderBy('recorded_at', 'desc')
            ->first();

        if ($lastPoint && $lastPoint->recorded_at) {
            $elapsed = Carbon::parse($lastPoint->recorded_at)->diffInSeconds(Carbon::now());
            if ($elapsed < self::MIN_UPDATE_INTERVAL_SECONDS) {
                throw new \Exception('LOCATION_RATE_LIMITED');
            }
        }

        return DB::transaction(function () use ($driver, $session, $locationData) {
            $now = Carbon::now();

            // Check for active trip tracking session
            $assignmentId = $this->getActiveAssignmentId($driver);

            // Create route point
            $routePoint = RoutePoint::create([
                'session_id' => $session->id,
                'assignment_id' => $assignmentId,
                'latitude' => $locationData['latitude'],
                'longitude' => $locationData['longitude'],
                'altitude' => $locationData['altitude'] ?? null,
                'speed' => $locationData['speed'] ?? null,
                'heading' => $locationData['heading'] ?? null,
                'accuracy' => $locationData['accuracy'] ?? null,
                'recorded_at' => $locationData['recorded_at'] ?? $now,
            ]);

            // Update driver coordinates and last_active_at
            $driver->update([
                'current_latitude' => $locationData['latitude'],
                'current_longitude' => $locationData['longitude'],
                'last_active_at' => $now,
            ]);

            // Trigger waiting time analysis during in_progress phase (non-blocking)
            if ($assignmentId && $this->waitingTimeService) {
                $assignment = DriverAssignment::find($assignmentId);
                if ($assignment && $assignment->trip_phase === TripPhase::IN_PROGRESS) {
                    try {
                        $this->waitingTimeService->analyzeRoutePoints($assignment);
                    } catch (\Exception $e) {
                        // Non-blocking — waiting time analysis failure should not
                        // prevent the location update from succeeding
                        \Illuminate\Support\Facades\Log::warning(
                            'Waiting time analysis failed for assignment ' . $assignmentId,
                            ['error' => $e->getMessage()]
                        );
                    }
                }
            }

            return $routePoint;
        });
    }

    /**
     * Get the active assignment ID for trip-specific route point linking.
     * Returns null if no trip tracking session is active.
     */
    private function getActiveAssignmentId(Driver $driver): ?string
    {
        $assignment = DriverAssignment::where('driver_id', $driver->id)
            ->whereIn('trip_phase', [
                TripPhase::ACCEPTED,
                TripPhase::PICKUP_ARRIVED,
                TripPhase::IN_PROGRESS,
            ])
            ->first();

        return $assignment?->id;
    }

    /**
     * Get the current location of a driver.
     *
     * @param Driver $driver The driver
     * @return array|null Location data or null if not available
     */
    public function getCurrentLocation(Driver $driver): ?array
    {
        if ($driver->current_latitude === null || $driver->current_longitude === null) {
            return null;
        }

        return [
            'latitude' => (float) $driver->current_latitude,
            'longitude' => (float) $driver->current_longitude,
            'last_active_at' => $driver->last_active_at?->toIso8601String(),
            'is_online' => $driver->is_online,
        ];
    }

    /**
     * Get locations of multiple online drivers.
     *
     * @param array|null $driverIds Optional array of driver IDs to filter
     * @return \Illuminate\Support\Collection Collection of driver locations
     */
    public function getOnlineDriverLocations(?array $driverIds = null): \Illuminate\Support\Collection
    {
        $query = Driver::where('is_online', true)
            ->whereNotNull('current_latitude')
            ->whereNotNull('current_longitude');

        if ($driverIds && count($driverIds) > 0) {
            $query->whereIn('id', $driverIds);
        }

        return $query->get()->map(function ($driver) {
            return [
                'driver_id' => $driver->id,
                'latitude' => (float) $driver->current_latitude,
                'longitude' => (float) $driver->current_longitude,
                'is_online' => $driver->is_online,
                'last_active_at' => $driver->last_active_at?->toIso8601String(),
            ];
        });
    }

    /**
     * Get route points for a session.
     *
     * @param DriverSession $session The session
     * @return \Illuminate\Support\Collection Collection of route points
     */
    public function getSessionRoutePoints(DriverSession $session): \Illuminate\Support\Collection
    {
        return $session->routePoints()
            ->orderBy('recorded_at', 'asc')
            ->get();
    }

    /**
     * Get the latest route point for a session.
     *
     * @param DriverSession $session The session
     * @return RoutePoint|null The latest route point or null
     */
    public function getLatestRoutePoint(DriverSession $session): ?RoutePoint
    {
        return $session->routePoints()
            ->orderBy('recorded_at', 'desc')
            ->first();
    }

    /**
     * Batch update locations (for bulk processing).
     *
     * @param Driver $driver The driver
     * @param array $locations Array of location data points
     * @return int Number of route points created
     * @throws \Exception If driver has no active session
     */
    public function batchUpdateLocations(Driver $driver, array $locations): int
    {
        return $this->syncBufferedLocations($driver, $locations)['saved_count'];
    }

    /**
     * Save buffered location samples uploaded after temporary network issues.
     *
     * Duplicate points are skipped when recorded_at + latitude + longitude
     * match an already stored session point or another point in the same payload.
     */
    public function syncBufferedLocations(Driver $driver, array $locations): array
    {
        $session = $driver->activeSession;

        if (!$session) {
            throw new \Exception('No active session');
        }

        $activeAssignmentId = $this->getActiveAssignmentId($driver);
        $normalizedLocations = collect($locations)
            ->map(function (array $locationData) use ($activeAssignmentId) {
                $recordedAt = Carbon::parse($locationData['recorded_at']);

                return [
                    'session_id' => null,
                    'assignment_id' => $locationData['assignment_id'] ?? $activeAssignmentId,
                    'latitude' => (float) $locationData['latitude'],
                    'longitude' => (float) $locationData['longitude'],
                    'altitude' => array_key_exists('altitude', $locationData) ? $locationData['altitude'] : null,
                    'speed' => array_key_exists('speed', $locationData) ? $locationData['speed'] : null,
                    'heading' => array_key_exists('heading', $locationData) ? $locationData['heading'] : null,
                    'accuracy' => array_key_exists('accuracy', $locationData) ? $locationData['accuracy'] : null,
                    'recorded_at' => $recordedAt,
                    'dedupe_key' => $this->buildLocationDedupeKey(
                        $recordedAt,
                        (float) $locationData['latitude'],
                        (float) $locationData['longitude']
                    ),
                ];
            })
            ->sortBy(function (array $locationData) {
                return $locationData['recorded_at']->timestamp;
            })
            ->values();

        if ($normalizedLocations->isEmpty()) {
            return [
                'saved_count' => 0,
                'skipped_count' => 0,
                'duplicate_count' => 0,
                'latest_saved_point' => null,
            ];
        }

        $recordedAtValues = $normalizedLocations
            ->map(fn (array $locationData) => $locationData['recorded_at']->format('Y-m-d H:i:s'))
            ->unique()
            ->values();

        $existingKeys = RoutePoint::query()
            ->where('session_id', $session->id)
            ->whereIn('recorded_at', $recordedAtValues)
            ->get(['recorded_at', 'latitude', 'longitude'])
            ->map(fn (RoutePoint $point) => $this->buildLocationDedupeKey(
                Carbon::parse($point->recorded_at),
                (float) $point->latitude,
                (float) $point->longitude
            ))
            ->flip();

        $payloadSeenKeys = [];
        $savedCount = 0;
        $duplicateCount = 0;
        $latestSavedPoint = null;

        DB::transaction(function () use (
            $driver,
            $session,
            $normalizedLocations,
            $existingKeys,
            &$payloadSeenKeys,
            &$savedCount,
            &$duplicateCount,
            &$latestSavedPoint
        ) {
            $now = Carbon::now();

            foreach ($normalizedLocations as $locationData) {
                $dedupeKey = $locationData['dedupe_key'];

                if (isset($payloadSeenKeys[$dedupeKey]) || $existingKeys->has($dedupeKey)) {
                    $duplicateCount++;
                    continue;
                }

                $payloadSeenKeys[$dedupeKey] = true;

                $routePoint = RoutePoint::create([
                    'session_id' => $session->id,
                    'assignment_id' => $locationData['assignment_id'],
                    'latitude' => $locationData['latitude'],
                    'longitude' => $locationData['longitude'],
                    'altitude' => $locationData['altitude'],
                    'speed' => $locationData['speed'],
                    'heading' => $locationData['heading'],
                    'accuracy' => $locationData['accuracy'],
                    'recorded_at' => $locationData['recorded_at'],
                ]);

                $savedCount++;
                $latestSavedPoint = $routePoint;
            }

            if ($latestSavedPoint) {
                $driver->update([
                    'current_latitude' => $latestSavedPoint->latitude,
                    'current_longitude' => $latestSavedPoint->longitude,
                    'last_active_at' => $now,
                ]);
            }
        });

        return [
            'saved_count' => $savedCount,
            'skipped_count' => $duplicateCount,
            'duplicate_count' => $duplicateCount,
            'latest_saved_point' => $latestSavedPoint,
        ];
    }

    private function buildLocationDedupeKey(Carbon $recordedAt, float $latitude, float $longitude): string
    {
        return implode('|', [
            $recordedAt->format('Y-m-d H:i:s'),
            number_format($latitude, 8, '.', ''),
            number_format($longitude, 8, '.', ''),
        ]);
    }

    /**
     * Validate location coordinates.
     *
     * @param float $latitude The latitude
     * @param float $longitude The longitude
     * @return bool True if valid, false otherwise
     */
    public function validateCoordinates(float $latitude, float $longitude): bool
    {
        // Valid latitude range: -90 to 90
        if ($latitude < -90 || $latitude > 90) {
            return false;
        }

        // Valid longitude range: -180 to 180
        if ($longitude < -180 || $longitude > 180) {
            return false;
        }

        return true;
    }

    /**
     * Check if location is within Sri Lanka service area (approximate bounds).
     *
     * @param float $latitude The latitude
     * @param float $longitude The longitude
     * @return bool True if within bounds, false otherwise
     */
    public function isWithinServiceArea(float $latitude, float $longitude): bool
    {
        // Sri Lanka approximate bounds
        $minLat = 5.9;
        $maxLat = 9.9;
        $minLng = 79.5;
        $maxLng = 82.0;

        return $latitude >= $minLat 
            && $latitude <= $maxLat 
            && $longitude >= $minLng 
            && $longitude <= $maxLng;
    }
}
