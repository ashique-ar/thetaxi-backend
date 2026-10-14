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
        $this->googleApiKey = config('services.google.maps_api_key')
            ?: config('services.google.places_api_key')
            ?: env('GOOGLE_MAPS_API_KEY')
            ?: env('GOOGLE_PLACES_API_KEY');

        if (!$this->googleApiKey) {
            Log::warning('Google Places API key not configured');
        }
    }

    /**
     * Get request headers for Google API calls, forwarding Referer to satisfy GCP HTTP referrer restrictions
     */
    private function getGoogleHeaders(Request $request, array $extraHeaders = []): array
    {
        $headers = array_merge([
            'X-Goog-Api-Key' => $this->googleApiKey,
            'X-Goog-Maps-Solution-ID' => 'gmp_git_agentskills_v1',
        ], $extraHeaders);

        // Satisfy Google Cloud Console HTTP Referrer (Websites) restriction
        $referer = $request->header('referer')
            ?: $request->header('origin')
            ?: config('services.google.maps_referer')
            ?: env('GOOGLE_MAPS_REFERER')
            ?: config('app.url');

        if ($referer) {
            $headers['Referer'] = $referer;
        }

        return $headers;
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
        $cacheKey = "places_autocomplete:v3:" . md5($query . $countryCode . $limit);
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
            $body = [
                'input' => $query,
                'languageCode' => 'en',
            ];

            if (!empty($countryCode)) {
                $body['includedRegionCodes'] = [strtolower($countryCode)];
            }

            $headers = $this->getGoogleHeaders($request, [
                'Content-Type' => 'application/json',
            ]);

            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->post('https://places.googleapis.com/v1/places:autocomplete', $body);

            if (!$response->successful()) {
                Log::error('Places Autocomplete (New) failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'query' => $query,
                ]);

                $status = $response->status();
                $message = 'Search service unavailable';
                if ($status === 400) {
                    $message = 'Invalid search request';
                } elseif ($status === 403) {
                    $message = 'Search service access denied';
                } elseif ($status === 429) {
                    $message = 'Search service temporarily unavailable';
                }

                return response()->json([
                    'status' => 'error',
                    'message' => $message,
                    'google_status' => $response->json('error.status') ?? 'ERROR',
                    'error_message' => $response->json('error.message') ?? $message,
                ], $status >= 400 && $status < 600 ? $status : 503);
            }

            $data = $response->json();
            $suggestions = $data['suggestions'] ?? [];
            $places = [];

            foreach (array_slice($suggestions, 0, $limit) as $item) {
                $pred = $item['placePrediction'] ?? null;
                if (!$pred) {
                    continue;
                }

                $placeId = $pred['placeId'] ?? '';
                if (empty($placeId) && !empty($pred['place'])) {
                    $placeId = str_replace('places/', '', $pred['place']);
                }

                $description = $pred['text']['text'] ?? '';
                if (empty($description) && !empty($pred['structuredFormat']['mainText']['text'])) {
                    $main = $pred['structuredFormat']['mainText']['text'];
                    $secondary = $pred['structuredFormat']['secondaryText']['text'] ?? '';
                    $description = $secondary ? "{$main}, {$secondary}" : $main;
                }

                if (!empty($placeId)) {
                    $places[] = [
                        'description' => $description,
                        'place_id' => $placeId,
                    ];
                }
            }

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
            $cleanPlaceId = str_replace('places/', '', $placeId);

            $headers = $this->getGoogleHeaders($request, [
                'X-Goog-FieldMask' => 'id,displayName,formattedAddress,location,addressComponents',
            ]);

            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->get("https://places.googleapis.com/v1/places/{$cleanPlaceId}");

            if (!$response->successful()) {
                Log::error('Place details (New) failed', [
                    'place_id' => $placeId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                if ($response->status() === 404) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Place not found'
                    ], 404);
                }

                return response()->json([
                    'status' => 'error',
                    'message' => 'Place details service unavailable'
                ], $response->status() >= 400 && $response->status() < 600 ? $response->status() : 503);
            }

            $data = $response->json();
            $place = $this->formatPlaceData($data);

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
            $headers = $this->getGoogleHeaders($request);

            $response = Http::withHeaders($headers)->timeout(10)->get('https://maps.googleapis.com/maps/api/geocode/json', [
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
     * Format place data from Google API response (supports Places API New and Geocoding API)
     */
    private function formatPlaceData(array $place): array
    {
        $formattedAddress = $place['formattedAddress'] ?? ($place['formatted_address'] ?? '');

        $lat = 0.0;
        $lng = 0.0;
        if (isset($place['location']['latitude']) || isset($place['location']['longitude'])) {
            $lat = (float) ($place['location']['latitude'] ?? 0);
            $lng = (float) ($place['location']['longitude'] ?? 0);
        } elseif (isset($place['geometry']['location'])) {
            $geom = $place['geometry']['location'];
            $lat = (float) ($geom['lat'] ?? 0);
            $lng = (float) ($geom['lng'] ?? 0);
        }

        $name = '';
        if (isset($place['displayName']['text'])) {
            $name = $place['displayName']['text'];
        } elseif (isset($place['name']) && is_string($place['name'])) {
            $name = $place['name'];
        }

        $placeId = $place['id'] ?? ($place['place_id'] ?? '');
        if (is_string($placeId) && str_starts_with($placeId, 'places/')) {
            $placeId = substr($placeId, 7);
        }

        $rawComponents = $place['addressComponents'] ?? ($place['address_components'] ?? []);
        $city = '';
        $country = '';
        $normalizedComponents = [];

        foreach ($rawComponents as $component) {
            $types = $component['types'] ?? [];
            $longName = $component['longText'] ?? ($component['long_name'] ?? '');
            $shortName = $component['shortText'] ?? ($component['short_name'] ?? $longName);

            $normalizedComponents[] = [
                'long_name' => $longName,
                'short_name' => $shortName,
                'longText' => $longName,
                'shortText' => $shortName,
                'types' => $types,
            ];

            if (in_array('locality', $types) || in_array('administrative_area_level_2', $types)) {
                if (empty($city)) {
                    $city = $longName;
                }
            }

            if (in_array('country', $types)) {
                $country = $longName;
            }
        }

        return [
            'address' => $formattedAddress,
            'formatted_address' => $formattedAddress,
            'latitude' => $lat,
            'longitude' => $lng,
            'lat' => $lat,
            'lng' => $lng,
            'city' => $city,
            'country' => $country ?: 'Sri Lanka',
            'place_id' => $placeId,
            'name' => $name,
            'address_components' => $normalizedComponents,
        ];
    }

    /**
     * Get Google Maps / Places configuration for frontend clients
     */
    public function getConfig(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'api_key' => $this->googleApiKey ?: '',
            'data' => [
                'api_key' => $this->googleApiKey ?: '',
                'maps_api_key' => $this->googleApiKey ?: '',
            ],
        ]);
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
            'config_path' => 'services.google.maps_api_key',
            'env_key' => 'GOOGLE_MAPS_API_KEY'
        ]);
    }
}
