<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleMapsService
{
    private string $apiKey;
    private int $ttl; // seconds

    public function __construct()
    {
        // Use a dedicated MAPS key, or fall back to the PLACES key you already use
        $this->apiKey = config('services.google.places_api_key', '');
        $this->ttl = config('services.google.cache_ttl', 3600); // 1h default

        if (empty($this->apiKey)) {
            Log::warning('Google Maps API key not configured (services.google.maps_api_key / services.google.places_api_key)');
        }
    }

    /**
     * Get road distance and duration using Distance Matrix.
     * Returns array with 'distance_km' and 'duration_seconds'
     */
    public function distanceAndDuration(array|string $origin, array|string $destination, string $mode = 'driving', bool $avoidTolls = false, bool $avoidHighways = false): array
    {
        $o = $this->formatLocation($origin);
        $d = $this->formatLocation($destination);

        if (empty($this->apiKey) || !$o || !$d) {
            return ['distance_km' => 0.0, 'duration_seconds' => 0];
        }

        $cacheKey = 'gm:distancematrix:withduration:' . md5(json_encode([$o, $d, $mode, $avoidTolls, $avoidHighways]));
        return Cache::remember($cacheKey, $this->ttl, function () use ($o, $d, $mode, $avoidTolls, $avoidHighways) {
            try {
                $params = [
                    'key' => $this->apiKey,
                    'origins' => $o,
                    'destinations' => $d,
                    'mode' => $mode,
                    'units' => 'metric',
                ];
                if ($avoidTolls)
                    $params['avoid'] = ($params['avoid'] ?? '') . (empty($params['avoid']) ? 'tolls' : '|tolls');
                if ($avoidHighways)
                    $params['avoid'] = ($params['avoid'] ?? '') . (empty($params['avoid']) ? 'highways' : '|highways');

                $resp = Http::timeout(12)->get('https://maps.googleapis.com/maps/api/distancematrix/json', $params);
                if (!$resp->successful()) {
                    Log::error('Distance Matrix HTTP error', ['status' => $resp->status(), 'body' => $resp->body()]);
                    return $this->cachedDirectionsFallback($o, $d, $mode);
                }

                $data = $resp->json();
                if (($data['status'] ?? '') !== 'OK') {
                    Log::warning('Distance Matrix Google status not OK', ['status' => $data['status'] ?? 'UNKNOWN', 'error_message' => $data['error_message'] ?? null]);
                    return $this->cachedDirectionsFallback($o, $d, $mode);
                }

                $element = $data['rows'][0]['elements'][0] ?? null;
                if (!$element || ($element['status'] ?? '') !== 'OK') {
                    return $this->cachedDirectionsFallback($o, $d, $mode);
                }

                $meters = $element['distance']['value'] ?? 0;
                $seconds = $element['duration']['value'] ?? 0;

                return [
                    'distance_km' => $meters > 0 ? round($meters / 1000, 3) : 0.0,
                    'duration_seconds' => $seconds
                ];
            } catch (\Throwable $e) {
                Log::error('Distance Matrix exception', ['error' => $e->getMessage()]);
                return $this->cachedDirectionsFallback($o, $d, $mode);
            }
        });
    }

    private function cachedDirectionsFallback(string $origin, string $destination, string $mode): array
    {
        $key = 'gm:directions:withduration:' . md5(json_encode([$origin, $destination, $mode]));

        return Cache::remember($key, $this->ttl, fn () => $this->directionsFallbackWithDuration($origin, $destination, $mode));
    }

    /** Optional fallback using Directions API */
    private function directionsFallbackKm(string $origin, string $destination, string $mode = 'driving'): float
    {
        try {
            $params = [
                'key' => $this->apiKey,
                'origin' => $origin,
                'destination' => $destination,
                'mode' => $mode,
                'units' => 'metric',
            ];

            $resp = Http::connectTimeout(2)->timeout(5)->get('https://maps.googleapis.com/maps/api/directions/json', $params);
            if (!$resp->successful()) {
                Log::error('Directions HTTP error', ['status' => $resp->status(), 'body' => $resp->body()]);
                return 0.0;
            }

            $data = $resp->json();
            if (($data['status'] ?? '') !== 'OK' || empty($data['routes'][0]['legs'][0]['distance']['value'])) {
                return 0.0;
            }

            $meters = $data['routes'][0]['legs'][0]['distance']['value'];
            return $meters > 0 ? round($meters / 1000, 3) : 0.0;
        } catch (\Throwable $e) {
            Log::error('Directions exception', ['error' => $e->getMessage()]);
            return 0.0;
        }
    }

    /** Optional fallback using Directions API with duration */
    private function directionsFallbackWithDuration(string $origin, string $destination, string $mode = 'driving'): array
    {
        try {
            $params = [
                'key' => $this->apiKey,
                'origin' => $origin,
                'destination' => $destination,
                'mode' => $mode,
                'units' => 'metric',
            ];

            $resp = Http::timeout(12)->get('https://maps.googleapis.com/maps/api/directions/json', $params);
            if (!$resp->successful()) {
                Log::error('Directions HTTP error', ['status' => $resp->status(), 'body' => $resp->body()]);
                return ['distance_km' => 0.0, 'duration_seconds' => 0];
            }

            $data = $resp->json();
            if (($data['status'] ?? '') !== 'OK' || empty($data['routes'][0]['legs'][0])) {
                return ['distance_km' => 0.0, 'duration_seconds' => 0];
            }

            $leg = $data['routes'][0]['legs'][0];
            $meters = $leg['distance']['value'] ?? 0;
            $seconds = $leg['duration']['value'] ?? 0;

            return [
                'distance_km' => $meters > 0 ? round($meters / 1000, 3) : 0.0,
                'duration_seconds' => $seconds
            ];
        } catch (\Throwable $e) {
            Log::error('Directions exception', ['error' => $e->getMessage()]);
            return ['distance_km' => 0.0, 'duration_seconds' => 0];
        }
    }

    /** Normalize different location inputs into what Google accepts */
    private function formatLocation(array|string $loc): ?string
    {
        if (is_string($loc)) {
            // Accept "lat,lng" | "place_id:..." | free-text address
            return trim($loc);
        }

        // lat/lng variants
        $lat = $loc['lat'] ?? $loc['latitude'] ?? null;
        $lng = $loc['lng'] ?? $loc['longitude'] ?? null;

        // Airport selectors may use an IATA code (for example, CMB) as their
        // local identifier. It is not a Google Place ID, so use the supplied
        // coordinates instead of sending an invalid place_id to Google.
        $placeId = trim((string) ($loc['place_id'] ?? ''));
        $looksLikeAirportCode = preg_match('/^[A-Z0-9]{3}$/i', $placeId) === 1;
        if ($looksLikeAirportCode && is_numeric($lat) && is_numeric($lng)) {
            return $lat . ',' . $lng;
        }

        // A genuine Google Place ID remains the most precise location input.
        if ($placeId !== '') {
            return 'place_id:' . $placeId;
        }

        if (is_numeric($lat) && is_numeric($lng)) {
            return $lat . ',' . $lng;
        }

        // fallback: address strings if present
        foreach (['address', 'formatted_address', 'name'] as $k) {
            if (!empty($loc[$k]))
                return (string) $loc[$k];
        }

        return null;
    }

    /**
     * Calculate optimal route through multiple waypoints
     * 
     * @param array $waypoints Array of locations (each can be array or string)
     * @param bool $optimize Whether to optimize waypoint order
     * @param string $mode Travel mode (driving, walking, bicycling, transit)
     * @return array Route information with distance, duration, and optimized waypoints
     */
    public function calculateOptimalRoute(array $waypoints, bool $optimize = true, string $mode = 'driving'): array
    {
        if (count($waypoints) < 2) {
            return [
                'distance_km' => 0.0,
                'duration_seconds' => 0,
                'duration_minutes' => 0,
                'waypoints' => [],
                'polyline' => '',
            ];
        }

        if (empty($this->apiKey)) {
            Log::warning('Google Maps API key not configured for route calculation');
            return [
                'distance_km' => 0.0,
                'duration_seconds' => 0,
                'duration_minutes' => 0,
                'waypoints' => $waypoints,
                'polyline' => '',
            ];
        }

        $origin = array_shift($waypoints);
        $destination = array_pop($waypoints);

        $originStr = $this->formatLocation($origin);
        $destinationStr = $this->formatLocation($destination);

        if (!$originStr || !$destinationStr) {
            return [
                'distance_km' => 0.0,
                'duration_seconds' => 0,
                'duration_minutes' => 0,
                'waypoints' => [],
                'polyline' => '',
            ];
        }

        $cacheKey = 'gm:route:' . md5(json_encode([$originStr, $destinationStr, $waypoints, $optimize, $mode]));
        
        return Cache::remember($cacheKey, $this->ttl, function () use ($originStr, $destinationStr, $waypoints, $optimize, $mode) {
            try {
                $params = [
                    'key' => $this->apiKey,
                    'origin' => $originStr,
                    'destination' => $destinationStr,
                    'mode' => $mode,
                    'units' => 'metric',
                ];

                // Add waypoints if any
                if (!empty($waypoints)) {
                    $waypointStrs = array_map(fn($wp) => $this->formatLocation($wp), $waypoints);
                    $waypointStrs = array_filter($waypointStrs); // Remove nulls
                    
                    if (!empty($waypointStrs)) {
                        $waypointParam = implode('|', $waypointStrs);
                        if ($optimize) {
                            $waypointParam = 'optimize:true|' . $waypointParam;
                        }
                        $params['waypoints'] = $waypointParam;
                    }
                }

                $resp = Http::timeout(15)->get('https://maps.googleapis.com/maps/api/directions/json', $params);
                
                if (!$resp->successful()) {
                    Log::error('Directions API HTTP error', ['status' => $resp->status(), 'body' => $resp->body()]);
                    return [
                        'distance_km' => 0.0,
                        'duration_seconds' => 0,
                        'duration_minutes' => 0,
                        'waypoints' => [],
                        'polyline' => '',
                    ];
                }

                $data = $resp->json();
                
                if (($data['status'] ?? '') !== 'OK') {
                    Log::warning('Directions API status not OK', [
                        'status' => $data['status'] ?? 'UNKNOWN',
                        'error_message' => $data['error_message'] ?? null
                    ]);
                    return [
                        'distance_km' => 0.0,
                        'duration_seconds' => 0,
                        'duration_minutes' => 0,
                        'waypoints' => [],
                        'polyline' => '',
                    ];
                }

                $route = $data['routes'][0] ?? null;
                if (!$route) {
                    return [
                        'distance_km' => 0.0,
                        'duration_seconds' => 0,
                        'duration_minutes' => 0,
                        'waypoints' => [],
                        'polyline' => '',
                    ];
                }

                // Calculate total distance and duration from all legs
                $totalDistance = 0;
                $totalDuration = 0;

                foreach ($route['legs'] as $leg) {
                    $totalDistance += $leg['distance']['value'] ?? 0; // meters
                    $totalDuration += $leg['duration']['value'] ?? 0; // seconds
                }

                return [
                    'distance_km' => $totalDistance > 0 ? round($totalDistance / 1000, 3) : 0.0,
                    'duration_seconds' => $totalDuration,
                    'duration_minutes' => $totalDuration > 0 ? round($totalDuration / 60) : 0,
                    'waypoints' => $route['waypoint_order'] ?? [],
                    'polyline' => $route['overview_polyline']['points'] ?? '',
                ];
            } catch (\Throwable $e) {
                Log::error('Route calculation exception', ['error' => $e->getMessage()]);
                return [
                    'distance_km' => 0.0,
                    'duration_seconds' => 0,
                    'duration_minutes' => 0,
                    'waypoints' => [],
                    'polyline' => '',
                ];
            }
        });
    }
}
