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
        $this->apiKey = config('services.google.maps_api_key') ?: config('services.google.places_api_key', '');
        $this->ttl = config('services.google.cache_ttl', 3600); // 1h default

        if (empty($this->apiKey)) {
            Log::warning('Google Maps API key not configured (services.google.maps_api_key / services.google.places_api_key)');
        }
    }

    private function getHeaders(): array
    {
        $headers = [
            'X-Goog-Maps-Solution-ID' => 'gmp_git_agentskills_v1',
        ];

        $configuredReferer = config('services.google.maps_referer') ?: env('GOOGLE_MAPS_REFERER');
        if (in_array(strtolower((string)$configuredReferer), ['none', 'false', 'off', 'null'], true)) {
            return $headers;
        }

        $referer = $configuredReferer
            ?: request()?->header('referer')
            ?: request()?->header('origin')
            ?: config('app.url');

        if ($referer) {
            $headers['Referer'] = $referer;
        }

        return $headers;
    }

    /**
     * Get road distance and duration using the Routes API.
     * Returns array with 'distance_km' and 'duration_seconds'
     */
    public function distanceAndDuration(array|string $origin, array|string $destination, string $mode = 'driving', bool $avoidTolls = false, bool $avoidHighways = false): array
    {
        $o = $this->formatLocation($origin);
        $d = $this->formatLocation($destination);

        if (!$o || !$d) {
            throw new \RuntimeException('Google route distance cannot be calculated from the supplied locations.');
        }

        if ($o === $d || $this->sameCoordinates($o, $d)) {
            return ['distance_km' => 0.0, 'duration_seconds' => 0];
        }

        if (empty($this->apiKey)) {
            throw new \RuntimeException('Google route distance cannot be calculated from the supplied locations.');
        }

        $blockedKey = 'gm:routes:permission-denied:' . md5($this->apiKey);
        $cacheKey = 'gm:routes:v1:' . md5(json_encode([$o, $d, $mode, $avoidTolls, $avoidHighways]));
        return Cache::remember($cacheKey, $this->ttl, function () use ($o, $d, $mode, $avoidTolls, $avoidHighways, $blockedKey) {
            if (Cache::has($blockedKey)) {
                throw new \RuntimeException('Google Routes API access is blocked for this key.');
            }

            try {
                $travelMode = match (strtolower($mode)) {
                    'driving' => 'DRIVE',
                    'walking' => 'WALK',
                    'bicycling' => 'BICYCLE',
                    'transit' => 'TRANSIT',
                    default => strtoupper($mode),
                };
                $response = Http::withHeaders($this->getHeaders() + [
                    'Content-Type' => 'application/json',
                    'X-Goog-Api-Key' => $this->apiKey,
                    'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration',
                ])->timeout(12)->post('https://routes.googleapis.com/directions/v2:computeRoutes', [
                    'origin' => $this->routeWaypoint($o),
                    'destination' => $this->routeWaypoint($d),
                    'travelMode' => $travelMode,
                    'routingPreference' => 'TRAFFIC_UNAWARE',
                    'units' => 'METRIC',
                    'routeModifiers' => [
                        'avoidTolls' => $avoidTolls,
                        'avoidHighways' => $avoidHighways,
                    ],
                ]);

                if (!$response->successful()) {
                    if ($response->status() === 403) {
                        Cache::put($blockedKey, true, now()->addMinutes(5));
                    }
                    Log::warning('Google Routes API request failed', [
                        'status' => $response->status(),
                        'error_status' => $response->json('error.status'),
                        'error_message' => $response->json('error.message'),
                    ]);
                    throw new \RuntimeException('Google Routes API request failed.');
                }

                $route = $response->json('routes.0');
                if (!is_array($route) || !is_numeric($route['distanceMeters'] ?? null)) {
                    Log::warning('Google Routes API returned no route.');
                    throw new \RuntimeException('Google Routes API returned no route.');
                }

                return [
                    'distance_km' => round((float) $route['distanceMeters'] / 1000, 3),
                    'duration_seconds' => (int) round((float) rtrim((string) ($route['duration'] ?? '0s'), 's')),
                ];
            } catch (\Throwable $e) {
                if (!$e instanceof \RuntimeException) {
                    Log::warning('Google Routes API exception', ['error' => $e->getMessage()]);
                }
                throw $e;
            }
        });
    }

    private function routeWaypoint(string $location): array
    {
        if (str_starts_with($location, 'place_id:')) {
            return ['placeId' => substr($location, strlen('place_id:'))];
        }

        if (preg_match('/^(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)$/', $location, $matches)) {
            return ['location' => ['latLng' => [
                'latitude' => (float) $matches[1],
                'longitude' => (float) $matches[2],
            ]]];
        }

        return ['address' => $location];
    }

    private function sameCoordinates(string $origin, string $destination): bool
    {
        if (!preg_match('/^(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)$/', $origin, $a)
            || !preg_match('/^(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)$/', $destination, $b)) {
            return false;
        }

        return (float) $a[1] === (float) $b[1] && (float) $a[2] === (float) $b[2];
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
