<?php

namespace App\Services\Driver;

use App\Models\Booking\BookingItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RouteProviderGateway
{
    public function estimate(BookingItem $item, string $purpose, string $requestedProvider, string $idempotencyKey, bool $meteredConfirmed): array
    {
        abort_unless(config('route_evidence.estimates_enabled'), 409, 'Operational route estimates are disabled');
        $anchors = $this->anchors($item);
        $fingerprint = hash('sha256', json_encode([
            (string) $item->id, $purpose, $anchors, 'driving', $requestedProvider,
            config('route_evidence.policy_version'),
        ], JSON_THROW_ON_ERROR));
        $resultKey = "route-estimate:result:{$item->id}:{$idempotencyKey}";
        if ($cached = Cache::get($resultKey)) return $cached;

        return Cache::lock("route-estimate:lock:$fingerprint", 15)->block(5, function () use ($resultKey, $fingerprint, $anchors, $purpose, $requestedProvider, $meteredConfirmed, $item) {
            if ($cached = Cache::get($resultKey)) return $cached;
            $provider = $requestedProvider === 'google' ? 'google' : 'osrm';
            if ($provider === 'google') $this->authorizeGoogle($item, $purpose, $fingerprint, $meteredConfirmed);

            try {
                $route = $provider === 'google' ? $this->google($anchors) : $this->osrm($anchors);
                $this->recordSuccess($provider, $fingerprint);
                $result = [
                    'classification' => 'estimated_route', 'confidence' => 'low',
                    'provider' => $provider, 'purpose' => $purpose,
                    'distance_km' => round($route['distance_m'] / 1000, 3),
                    'duration_seconds' => $route['duration_seconds'],
                    'polyline' => $route['polyline'] ?? null,
                    'fingerprint' => $fingerprint, 'policy_version' => config('route_evidence.policy_version'),
                    'pricing_effect' => 'none', 'generated_at' => now()->toIso8601String(),
                ];
                Cache::put($resultKey, $result, now()->addMinutes(30));
                Log::info('operational_route_estimate_generated', ['booking_item_id' => $item->id, 'purpose' => $purpose, 'provider' => $provider, 'fingerprint' => $fingerprint]);
                return $result;
            } catch (Throwable $e) {
                $this->recordFailure($provider);
                Log::warning('operational_route_estimate_unavailable', ['booking_item_id' => $item->id, 'purpose' => $purpose, 'provider' => $provider, 'exception_class' => $e::class]);
                throw new RuntimeException('ROUTE_ESTIMATE_UNAVAILABLE');
            }
        });
    }

    private function anchors(BookingItem $item): array
    {
        $anchors = [
            ['latitude' => (float) $item->pickup_latitude, 'longitude' => (float) $item->pickup_longitude],
            ['latitude' => (float) $item->dropoff_latitude, 'longitude' => (float) $item->dropoff_longitude],
        ];
        foreach ($anchors as $anchor) abort_unless(abs($anchor['latitude']) <= 90 && abs($anchor['longitude']) <= 180 && ($anchor['latitude'] != 0 || $anchor['longitude'] != 0), 422, 'Server-owned route anchors are unavailable');
        return $anchors;
    }

    private function osrm(array $anchors): array
    {
        abort_unless(config('route_evidence.osrm.enabled'), 409, 'OSRM estimates are disabled');
        $coordinates = collect($anchors)->map(fn ($p) => $p['longitude'].','.$p['latitude'])->implode(';');
        $response = Http::timeout((int) config('route_evidence.osrm.timeout_seconds', 8))->acceptJson()->get(rtrim(config('route_evidence.osrm.base_url'), '/')."/route/v1/driving/$coordinates", ['overview' => 'full', 'geometries' => 'polyline6', 'alternatives' => 'false', 'steps' => 'false']);
        $route = $response->successful() ? $response->json('routes.0') : null;
        if (!$route) throw new RuntimeException('OSRM_UNAVAILABLE');
        return ['distance_m' => (float) $route['distance'], 'duration_seconds' => (int) round($route['duration']), 'polyline' => $route['geometry'] ?? null];
    }

    private function google(array $anchors): array
    {
        $this->ensureCircuitClosed('google');
        $key = config('route_evidence.google.api_key');
        if (!$key) throw new RuntimeException('GOOGLE_NOT_CONFIGURED');
        $response = Http::timeout((int) config('route_evidence.google.timeout_seconds', 8))
            ->withHeaders(['X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline'])
            ->post('https://routes.googleapis.com/directions/v2:computeRoutes', [
                'origin' => ['location' => ['latLng' => ['latitude' => $anchors[0]['latitude'], 'longitude' => $anchors[0]['longitude']]]],
                'destination' => ['location' => ['latLng' => ['latitude' => $anchors[1]['latitude'], 'longitude' => $anchors[1]['longitude']]]],
                'travelMode' => 'DRIVE', 'routingPreference' => 'TRAFFIC_UNAWARE',
            ]);
        $route = $response->successful() ? $response->json('routes.0') : null;
        if (!$route) throw new RuntimeException('GOOGLE_UNAVAILABLE');
        return ['distance_m' => (float) $route['distanceMeters'], 'duration_seconds' => (int) rtrim((string) $route['duration'], 's'), 'polyline' => data_get($route, 'polyline.encodedPolyline')];
    }

    private function authorizeGoogle(BookingItem $item, string $purpose, string $fingerprint, bool $confirmed): void
    {
        abort_unless(config('route_evidence.google.enabled') && $confirmed, 409, 'Metered provider confirmation is required');
        $this->ensureCircuitClosed('google');
        abort_if(Cache::has("route-estimate:google:fingerprint:$fingerprint"), 409, 'A Google estimate already exists for this booking item and purpose');
        $daily = (int) config('route_evidence.google.daily_budget', 0);
        $monthly = (int) config('route_evidence.google.monthly_budget', 0);
        abort_if($daily < 1 || $monthly < 1, 409, 'Google route budget is unavailable');
        abort_if((int) Cache::get('route-estimate:google:daily:'.now()->format('Y-m-d'), 0) >= $daily, 429, 'Daily Google route budget reached');
        abort_if((int) Cache::get('route-estimate:google:monthly:'.now()->format('Y-m'), 0) >= $monthly, 429, 'Monthly Google route budget reached');
    }

    private function recordSuccess(string $provider, string $fingerprint): void
    {
        if ($provider !== 'google') return;
        $dailyKey = 'route-estimate:google:daily:'.now()->format('Y-m-d');
        $monthlyKey = 'route-estimate:google:monthly:'.now()->format('Y-m');
        Cache::put($dailyKey, (int) Cache::get($dailyKey, 0) + 1, now()->endOfDay());
        Cache::put($monthlyKey, (int) Cache::get($monthlyKey, 0) + 1, now()->endOfMonth());
        Cache::put("route-estimate:google:fingerprint:$fingerprint", true, now()->addMonth());
        Cache::forget('route-estimate:circuit:google:failures');
    }

    private function recordFailure(string $provider): void
    {
        if ($provider !== 'google') return;
        $failures = Cache::increment('route-estimate:circuit:google:failures');
        if ($failures >= (int) config('route_evidence.google.failure_threshold', 3)) Cache::put('route-estimate:circuit:google:open', true, now()->addMinutes((int) config('route_evidence.google.circuit_minutes', 15)));
    }

    private function ensureCircuitClosed(string $provider): void
    {
        abort_if(Cache::has("route-estimate:circuit:$provider:open"), 503, 'Route provider is temporarily unavailable');
    }
}
