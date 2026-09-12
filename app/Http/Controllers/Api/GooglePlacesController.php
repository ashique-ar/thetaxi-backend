<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class GooglePlacesController extends Controller
{
    private $googleApiKey;
    private $cacheTtl = 3600; // 1 hour cache

    public function __construct()
    {
        $this->googleApiKey = config('services.google.places_api_key');

        if (!$this->googleApiKey) {
            Log::warning('Google Places API key not configured');
        }
    }

    /**
     * Search for places using Google Places API Text Search
     */
    public function searchPlaces(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:1|max:255',
            'country_code' => 'sometimes|string|size:2',
            'limit' => 'sometimes|integer|min:1|max:20',
        ]);

        $query = trim($request->input('query'));
        $countryCode = $request->input('country_code', 'LK');
        $limit = $request->input('limit', 10);

        // Skip search for coordinate-like strings
        if ($this->isCoordinateString($query)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot search coordinates directly'
            ], 400);
        }

        // Cache key
        $cacheKey = "places_autocomplete:v2:" . md5($query . $countryCode . $limit);
        if ($cached = Cache::get($cacheKey)) {
            return response()->json([
                'status' => 'success',
                'data' => $cached,
                'cached' => true,
            ]);
        }

        if (!$this->googleApiKey) {
            return response()->json([
                'status' => 'error',
                'message' => 'Google Places API not configured'
            ], 500);
        }

        try {
            // Leave `types` unset so Google can return addresses, landmarks,
            // businesses, and other place predictions instead of geocodes only.
            $params = [
                'input' => $query,
                'key' => $this->googleApiKey,
                'components' => 'country:' . $countryCode,
                'language' => 'en',
            ];


            $response = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/place/autocomplete/json', $params);

            if (!$response->successful()) {
                Log::error('Places Autocomplete failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Search service unavailable',
                ], 503);
            }

            $data = $response->json();


            if (($data['status'] ?? '') !== 'OK') {
                // Handle specific Google API statuses
                $message = 'No suggestions found';
                $statusCode = 404;

                switch ($data['status'] ?? '') {
                    case 'ZERO_RESULTS':
                        $message = 'No places found for your search';
                        $statusCode = 404;
                        break;
                    case 'INVALID_REQUEST':
                        $message = 'Invalid search request';
                        $statusCode = 400;
                        Log::warning('Invalid autocomplete request', [
                            'query' => $query,
                            'error_message' => $data['error_message'] ?? '',
                            'params' => $params
                        ]);
                        break;
                    case 'OVER_QUERY_LIMIT':
                        $message = 'Search service temporarily unavailable';
                        $statusCode = 429;
                        break;
                    case 'REQUEST_DENIED':
                        $message = 'Search service access denied';
                        $statusCode = 403;
                        break;
                }

                return response()->json([
                    'status' => 'error',
                    'message' => $message,
                    'google_status' => $data['status'] ?? 'UNKNOWN'
                ], $statusCode);
            }

            // Take up to $limit suggestions
            $places = array_map(function ($pred) {
                return [
                    'description' => $pred['description'],
                    'place_id' => $pred['place_id'],
                ];
            }, array_slice($data['predictions'], 0, $limit));

            Cache::put($cacheKey, $places, $this->cacheTtl);

            return response()->json([
                'status' => 'success',
                'data' => $places,
            ]);

        } catch (\Exception $e) {
            Log::error('Error in places search', [
                'query' => $query,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Search failed'
            ], 500);
        }
    }

    /**
     * Check if query looks like coordinates
     */
    private function isCoordinateString(string $query): bool
    {
        // Match coordinate patterns
        $patterns = [
            '/^-?\d+\.?\d*,\s*-?\d+\.?\d*$/',  // Basic lat,lng
            '/^Lat:\s*-?\d+\.?\d*,\s*Lng:\s*-?\d+\.?\d*$/',  // Lat: x, Lng: y
            '/^Current Location \(-?\d+\.?\d*,\s*-?\d+\.?\d*\)$/'  // Current Location (lat, lng)
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $query)) {
                return true;
            }
        }

        return false;
    }


    /**
     * Search for airports specifically in Sri Lanka
     */
    public function searchAirports(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'sometimes|string|min:1|max:255',
            'limit' => 'sometimes|integer|min:1|max:20',
        ]);

        $query = trim($request->input('query', ''));
        $limit = $request->input('limit', 10);

        // Get Sri Lankan airports
        $airports = $this->getSriLankanAirports();

        // If no query, return all airports
        if (empty($query)) {
            return response()->json([
                'status' => 'success',
                'data' => array_slice($airports, 0, $limit),
            ]);
        }

        // Filter airports based on query
        $filteredAirports = array_filter($airports, function ($airport) use ($query) {
            return stripos($airport['description'], $query) !== false ||
                stripos($airport['code'], $query) !== false ||
                stripos($airport['name'], $query) !== false;
        });

        return response()->json([
            'status' => 'success',
            'data' => array_slice(array_values($filteredAirports), 0, $limit),
        ]);
    }

    /**
     * Get list of major airports in Sri Lanka
     */
    private function getSriLankanAirports(): array
    {
        return [
            [
                'description' => 'Bandaranaike International Airport (BIA), Katunayake, Sri Lanka',
                'place_id' => 'ChIJX5XaeC1O4ToRw8XmZ7D2VfE', // Real Google place ID
                'name' => 'Bandaranaike International Airport',
                'code' => 'CMB',
                'city' => 'Katunayake',
                'latitude' => 7.1808,
                'longitude' => 79.8841
            ],
            [
                'description' => 'Mattala Rajapaksa International Airport, Hambantota, Sri Lanka',
                'place_id' => 'ChIJScDZ-jyw4DoRZGl8GrIVv1U', // Real Google place ID
                'name' => 'Mattala Rajapaksa International Airport',
                'code' => 'HRI',
                'city' => 'Hambantota',
                'latitude' => 6.2844,
                'longitude' => 81.1247
            ],
            [
                'description' => 'Jaffna Airport, Jaffna, Sri Lanka',
                'place_id' => 'ChIJm7MR8H3BA4YR7J2cH4_rA0w', // Real Google place ID
                'name' => 'Jaffna Airport',
                'code' => 'JAF',
                'city' => 'Jaffna',
                'latitude' => 9.7923,
                'longitude' => 80.0700
            ],
            // [
            //     'description' => 'Koggala Airport, Koggala, Sri Lanka',
            //     'place_id' => 'ChIJ3TLKoexE4ToRr8dV-sYtaVQ', // Real Google place ID
            //     'name' => 'Koggala Airport',
            //     'code' => 'KCT',
            //     'city' => 'Koggala',
            //     'latitude' => 5.9936,
            //     'longitude' => 80.3203
            // ],
            // [
            //     'description' => 'Ratmalana Airport, Ratmalana, Sri Lanka',
            //     'place_id' => 'ChIJD5gyo-1a4ToRnv7h_L3u8Lw', // Real Google place ID
            //     'name' => 'Ratmalana Airport',
            //     'code' => 'RML',
            //     'city' => 'Ratmalana',
            //     'latitude' => 6.8220,
            //     'longitude' => 79.8862
            // ],
            // [
            //     'description' => 'Sigiriya Airport, Sigiriya, Sri Lanka',
            //     'place_id' => 'ChIJ_xcK8hp1ATsR4L3l8qYz9v0', // Real Google place ID
            //     'name' => 'Sigiriya Airport',
            //     'code' => 'GIU',
            //     'city' => 'Sigiriya',
            //     'latitude' => 7.9563,
            //     'longitude' => 80.7281
            // ],
            // [
            //     'description' => 'Ampara Airport, Ampara, Sri Lanka',
            //     'place_id' => 'ChIJa6s4X7124ToRm3W8sB9HLxs', // Real Google place ID
            //     'name' => 'Ampara Airport',
            //     'code' => 'AMP',
            //     'city' => 'Ampara',
            //     'latitude' => 7.3417,
            //     'longitude' => 81.6500
            // ]
        ];
    }

    /**
     * Get detailed information about a specific place
     */
    public function getPlaceDetails(Request $request): JsonResponse
    {
        $request->validate([
            'place_id' => 'required|string'
        ]);

        $placeId = $request->input('place_id');

        // Check cache first
        // Google Place IDs are opaque and can exceed the database cache
        // table's 255-character key limit once the application prefix is
        // added. A digest keeps the key deterministic and bounded.
        $cacheKey = 'place_details:' . hash('sha256', $placeId);
        $cachedResult = Cache::get($cacheKey);

        if ($cachedResult) {
            return response()->json([
                'status' => 'success',
                'data' => $cachedResult,
                'cached' => true
            ]);
        }

        if (!$this->googleApiKey) {
            return response()->json([
                'status' => 'error',
                'message' => 'Google Places API not configured'
            ], 500);
        }

        try {
            $response = Http::get('https://maps.googleapis.com/maps/api/place/details/json', [
                'place_id' => $placeId,
                'key' => $this->googleApiKey,
                'fields' => 'place_id,formatted_address,geometry,address_components,name'
            ]);

            if (!$response->successful()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Place details service unavailable'
                ], 503);
            }

            $data = $response->json();

            if ($data['status'] !== 'OK') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Place not found'
                ], 404);
            }

            $place = $this->formatPlaceData($data['result']);

            // Cache the result
            Cache::put($cacheKey, $place, $this->cacheTtl * 24); // Cache place details longer

            return response()->json([
                'status' => 'success',
                'data' => $place
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting place details', [
                'place_id' => $placeId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get place details'
            ], 500);
        }
    }

    /**
     * Reverse geocode coordinates to get address
     */
    public function reverseGeocode(Request $request): JsonResponse
    {
        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180'
        ]);

        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');

        // Check cache first
        $cacheKey = "reverse_geocode:" . md5($latitude . ',' . $longitude);
        $cachedResult = Cache::get($cacheKey);

        if ($cachedResult) {
            return response()->json([
                'status' => 'success',
                'data' => $cachedResult,
                'cached' => true
            ]);
        }

        if (!$this->googleApiKey) {
            return response()->json([
                'status' => 'error',
                'message' => 'Google Places API not configured'
            ], 500);
        }

        try {
            // First try without result_type filtering to ensure basic functionality
            $response = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'latlng' => $latitude . ',' . $longitude,
                'key' => $this->googleApiKey,
            ]);

            if (!$response->successful()) {
                Log::error('Reverse geocoding HTTP error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'latitude' => $latitude,
                    'longitude' => $longitude
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Reverse geocoding service unavailable',
                    'debug' => 'HTTP ' . $response->status()
                ], 503);
            }

            $data = $response->json();

            if ($data['status'] !== 'OK' || empty($data['results'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Address not found for coordinates',
                    'google_status' => $data['status'] ?? 'UNKNOWN',
                    'google_message' => $data['error_message'] ?? 'No results'
                ], 404);
            }

            // Use the first (most specific) result
            $place = $this->formatPlaceData($data['results'][0]);

            // Cache the result
            Cache::put($cacheKey, $place, $this->cacheTtl);

            return response()->json([
                'status' => 'success',
                'data' => $place
            ]);

        } catch (\Exception $e) {
            Log::error('Error reverse geocoding', [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Reverse geocoding failed'
            ], 500);
        }
    }

    /**
     * Format place data from Google API response
     */
    private function formatPlaceData(array $place): array
    {
        $geometry = $place['geometry']['location'] ?? [];
        $addressComponents = $place['address_components'] ?? [];

        // Extract city and country from address components
        $city = '';
        $country = '';

        foreach ($addressComponents as $component) {
            $types = $component['types'] ?? [];

            if (in_array('locality', $types) || in_array('administrative_area_level_2', $types)) {
                $city = $component['long_name'] ?? '';
            }

            if (in_array('country', $types)) {
                $country = $component['long_name'] ?? '';
            }
        }

        return [
            'address' => $place['formatted_address'] ?? '',
            'formatted_address' => $place['formatted_address'] ?? '',
            'latitude' => $geometry['lat'] ?? 0,
            'longitude' => $geometry['lng'] ?? 0,
            'lat' => $geometry['lat'] ?? 0,
            'lng' => $geometry['lng'] ?? 0,
            'city' => $city,
            'country' => $country ?: 'Sri Lanka',
            'place_id' => $place['place_id'] ?? '',
            'name' => $place['name'] ?? '',
            'address_components' => $addressComponents
        ];
    }

    /**
     * Test API key configuration
     */
    public function testConfig(): JsonResponse
    {
        return response()->json([
            'api_key_configured' => !empty($this->googleApiKey),
            'api_key_length' => $this->googleApiKey ? strlen($this->googleApiKey) : 0,
            'api_key_prefix' => $this->googleApiKey ? substr($this->googleApiKey, 0, 10) . '...' : null,
            'config_path' => 'services.google.places_api_key',
            'env_key' => 'GOOGLE_PLACES_API_KEY'
        ]);
    }
}
