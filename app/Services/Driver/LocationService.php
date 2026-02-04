<?php

namespace App\Services\Driver;

use App\Models\Driver\Driver;
use App\Models\Driver\DriverSession;
use App\Models\Driver\RoutePoint;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Location Service
 * 
 * Handles driver location updates, creating route points for tracking,
 * and updating driver coordinates.
 * 
 * @see Requirements 6.2, 6.3, 6.4, 6.5, 6.6
 */
class LocationService
{
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

        return DB::transaction(function () use ($driver, $session, $locationData) {
            $now = Carbon::now();

            // Create route point
            $routePoint = RoutePoint::create([
                'session_id' => $session->id,
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

            return $routePoint;
        });
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
        $session = $driver->activeSession;

        if (!$session) {
            throw new \Exception('No active session');
        }

        $count = 0;

        DB::transaction(function () use ($driver, $session, $locations, &$count) {
            $now = Carbon::now();
            $latestLocation = null;

            foreach ($locations as $locationData) {
                RoutePoint::create([
                    'session_id' => $session->id,
                    'latitude' => $locationData['latitude'],
                    'longitude' => $locationData['longitude'],
                    'altitude' => $locationData['altitude'] ?? null,
                    'speed' => $locationData['speed'] ?? null,
                    'heading' => $locationData['heading'] ?? null,
                    'accuracy' => $locationData['accuracy'] ?? null,
                    'recorded_at' => $locationData['recorded_at'] ?? $now,
                ]);

                $latestLocation = $locationData;
                $count++;
            }

            // Update driver with the latest location
            if ($latestLocation) {
                $driver->update([
                    'current_latitude' => $latestLocation['latitude'],
                    'current_longitude' => $latestLocation['longitude'],
                    'last_active_at' => $now,
                ]);
            }
        });

        return $count;
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
