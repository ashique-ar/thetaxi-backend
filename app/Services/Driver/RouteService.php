<?php

namespace App\Services\Driver;

use App\Models\Driver\DriverSession;
use App\Models\Driver\RoutePoint;
use Illuminate\Support\Collection;

/**
 * Route Service
 * 
 * Handles route point retrieval and distance calculations using the Haversine formula.
 * 
 * @see Requirements 7.1, 7.2, 7.3, 7.4
 */
class RouteService
{
    /**
     * Earth's radius in kilometers.
     */
    private const EARTH_RADIUS_KM = 6371.0;

    /**
     * Get route points for a session ordered by recorded_at.
     *
     * @param DriverSession $session The session to get route for
     * @return Collection Collection of RoutePoint models
     * 
     * @see Requirement 7.1 - Query route points by session ID
     * @see Requirement 7.2 - Return points ordered by recorded_at ascending
     */
    public function getSessionRoute(DriverSession $session): Collection
    {
        return $session->routePoints()
            ->orderBy('recorded_at', 'asc')
            ->get();
    }

    /**
     * Calculate total distance for a session from its route points.
     *
     * @param DriverSession $session The session to calculate distance for
     * @return float Total distance in kilometers
     * 
     * @see Requirement 7.3 - Calculate distance using Haversine formula
     * @see Requirement 7.4 - Calculate and store total_distance_km on session close
     */
    public function calculateDistance(DriverSession $session): float
    {
        $routePoints = $this->getSessionRoute($session);
        return $this->calculateDistanceFromPoints($routePoints);
    }

    /**
     * Calculate total distance from a collection of route points.
     *
     * @param Collection|array $points Collection or array of points with latitude/longitude
     * @return float Total distance in kilometers
     * 
     * @see Requirement 7.3 - Calculate distance using Haversine formula
     */
    public function calculateDistanceFromPoints($points): float
    {
        if ($points instanceof Collection) {
            $points = $points->toArray();
        }

        if (count($points) < 2) {
            return 0.0;
        }

        $totalDistance = 0.0;

        for ($i = 1; $i < count($points); $i++) {
            $prevPoint = $points[$i - 1];
            $currPoint = $points[$i];

            $lat1 = $this->getLatitude($prevPoint);
            $lng1 = $this->getLongitude($prevPoint);
            $lat2 = $this->getLatitude($currPoint);
            $lng2 = $this->getLongitude($currPoint);

            $totalDistance += $this->haversineDistance($lat1, $lng1, $lat2, $lng2);
        }

        return round($totalDistance, 2);
    }

    /**
     * Calculate the distance between two points using the Haversine formula.
     * 
     * The Haversine formula determines the great-circle distance between two points
     * on a sphere given their longitudes and latitudes.
     *
     * @param float $lat1 Latitude of point 1 in degrees
     * @param float $lng1 Longitude of point 1 in degrees
     * @param float $lat2 Latitude of point 2 in degrees
     * @param float $lng2 Longitude of point 2 in degrees
     * @return float Distance in kilometers
     * 
     * @see Requirement 7.3 - Haversine formula for distance calculation
     */
    public function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        // Convert degrees to radians
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        // Haversine formula
        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
             cos($lat1Rad) * cos($lat2Rad) *
             sin($deltaLng / 2) * sin($deltaLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_KM * $c;
    }

    /**
     * Get route statistics for a session.
     *
     * @param DriverSession $session The session
     * @return array Route statistics
     */
    public function getRouteStats(DriverSession $session): array
    {
        $routePoints = $this->getSessionRoute($session);
        $pointCount = $routePoints->count();

        if ($pointCount === 0) {
            return [
                'point_count' => 0,
                'total_distance_km' => 0.0,
                'average_speed_kmh' => 0.0,
                'max_speed_kmh' => 0.0,
                'duration_seconds' => 0,
                'start_point' => null,
                'end_point' => null,
            ];
        }

        $totalDistance = $this->calculateDistanceFromPoints($routePoints);
        
        $speeds = $routePoints->pluck('speed')->filter()->values();
        $averageSpeed = $speeds->count() > 0 ? $speeds->avg() : 0.0;
        $maxSpeed = $speeds->count() > 0 ? $speeds->max() : 0.0;

        $firstPoint = $routePoints->first();
        $lastPoint = $routePoints->last();
        
        $durationSeconds = 0;
        if ($firstPoint && $lastPoint && $firstPoint->recorded_at && $lastPoint->recorded_at) {
            $durationSeconds = $lastPoint->recorded_at->diffInSeconds($firstPoint->recorded_at);
        }

        return [
            'point_count' => $pointCount,
            'total_distance_km' => round($totalDistance, 2),
            'average_speed_kmh' => round($averageSpeed, 2),
            'max_speed_kmh' => round($maxSpeed, 2),
            'duration_seconds' => $durationSeconds,
            'start_point' => $firstPoint ? [
                'latitude' => (float) $firstPoint->latitude,
                'longitude' => (float) $firstPoint->longitude,
                'recorded_at' => $firstPoint->recorded_at?->toIso8601String(),
            ] : null,
            'end_point' => $lastPoint ? [
                'latitude' => (float) $lastPoint->latitude,
                'longitude' => (float) $lastPoint->longitude,
                'recorded_at' => $lastPoint->recorded_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * Get route points within a time range.
     *
     * @param DriverSession $session The session
     * @param \Carbon\Carbon $from Start time
     * @param \Carbon\Carbon $to End time
     * @return Collection Collection of RoutePoint models
     */
    public function getRoutePointsInRange(DriverSession $session, \Carbon\Carbon $from, \Carbon\Carbon $to): Collection
    {
        return $session->routePoints()
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at', 'asc')
            ->get();
    }

    /**
     * Simplify route by reducing point density.
     * 
     * Uses a simple distance-based algorithm to reduce the number of points
     * while maintaining the general shape of the route.
     *
     * @param Collection $points Original route points
     * @param float $minDistanceKm Minimum distance between points to keep
     * @return Collection Simplified route points
     */
    public function simplifyRoute(Collection $points, float $minDistanceKm = 0.05): Collection
    {
        if ($points->count() <= 2) {
            return $points;
        }

        $simplified = collect();
        $simplified->push($points->first());

        $lastKept = $points->first();

        foreach ($points->slice(1, -1) as $point) {
            $distance = $this->haversineDistance(
                $this->getLatitude($lastKept),
                $this->getLongitude($lastKept),
                $this->getLatitude($point),
                $this->getLongitude($point)
            );

            if ($distance >= $minDistanceKm) {
                $simplified->push($point);
                $lastKept = $point;
            }
        }

        // Always include the last point
        $simplified->push($points->last());

        return $simplified;
    }

    /**
     * Get latitude from a point (handles both model and array).
     *
     * @param mixed $point RoutePoint model or array
     * @return float Latitude
     */
    protected function getLatitude($point): float
    {
        if ($point instanceof RoutePoint) {
            return (float) $point->latitude;
        }

        return (float) ($point['latitude'] ?? 0);
    }

    /**
     * Get longitude from a point (handles both model and array).
     *
     * @param mixed $point RoutePoint model or array
     * @return float Longitude
     */
    protected function getLongitude($point): float
    {
        if ($point instanceof RoutePoint) {
            return (float) $point->longitude;
        }

        return (float) ($point['longitude'] ?? 0);
    }

    /**
     * Calculate bearing between two points.
     *
     * @param float $lat1 Latitude of point 1
     * @param float $lng1 Longitude of point 1
     * @param float $lat2 Latitude of point 2
     * @param float $lng2 Longitude of point 2
     * @return float Bearing in degrees (0-360)
     */
    public function calculateBearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLng = deg2rad($lng2 - $lng1);

        $x = sin($deltaLng) * cos($lat2Rad);
        $y = cos($lat1Rad) * sin($lat2Rad) - sin($lat1Rad) * cos($lat2Rad) * cos($deltaLng);

        $bearing = rad2deg(atan2($x, $y));

        // Normalize to 0-360
        return fmod($bearing + 360, 360);
    }
}
