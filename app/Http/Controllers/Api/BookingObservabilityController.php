<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\AuditLog;
use App\Services\BookingObservabilityService;
use App\Services\BookingOperationsHealthMonitor;
use App\Services\WebsiteSettingsService;
use App\Services\Driver\RouteProviderGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookingObservabilityController extends Controller
{
    public function __construct(
        private readonly BookingObservabilityService $observability,
        private readonly BookingOperationsHealthMonitor $healthMonitor,
        private readonly RouteProviderGateway $routeProviderGateway,
        private readonly WebsiteSettingsService $settingsService,
    ) {}

    public function trace(Request $request, Booking $booking): JsonResponse
    {
        $itemId = $this->validatedItemId($request, $booking, false);
        $validated = $request->validate([
            'filter' => ['nullable', 'in:all,operations,journey,recovery,finance,communications,documents,administration,exceptions'],
        ]);
        $limit = max(1, min((int) $request->query('limit', 50), 100));
        return response()->json(['status' => 'success', 'data' => $this->observability->trace(
            $booking,
            $itemId,
            $request->query('cursor'),
            $limit,
            $validated['filter'] ?? 'all'
        )]);
    }

    public function trackingSummary(Request $request, Booking $booking): JsonResponse
    {
        $itemId = $this->validatedItemId($request, $booking, true);
        if (!$this->settingsService->isDriverMobileAppEnabled()) {
            return response()->json(['status' => 'success', 'data' => [
                'booking_id' => (string) $booking->id,
                'booking_item_id' => $itemId,
                'assignment_id' => null,
                'driver_id' => null,
                'tracking_enabled' => false,
                'enabled' => false,
                'is_online' => false,
                'freshness' => 'never_reported',
                'last_reported_at' => null,
                'active_position' => null,
                'trip_phase' => null,
                'total_points' => 0,
                'route_truncated' => false,
                'has_replay' => false,
                'distance_evidence' => [
                    'classification' => 'unavailable', 'source' => 'driver_route_points',
                    'confidence' => 'none', 'recorded_distance_km' => null,
                    'coverage_status' => 'not_recorded', 'distance_trustworthy' => false,
                    'recorded_point_count' => 0, 'accepted_point_count' => 0,
                    'rejected_point_count' => 0, 'gap_count' => 0, 'longest_gap_seconds' => 0,
                    'first_valid_point_at' => null, 'last_valid_point_at' => null,
                    'calculation_version' => 'contiguous-v2', 'warnings' => [], 'pricing_effect' => 'none',
                ],
                'pricing_effect' => 'none',
                'generated_at' => now()->utc()->toIso8601String(),
            ]]);
        }
        $data = $this->observability->trackingSummary($booking, $itemId);
        $data['tracking_health'] = $this->healthMonitor->latestDriverTrackingHealth(
            isset($data['assignment_id']) ? (string) $data['assignment_id'] : null
        );
        $this->healthMonitor->recordTrackingFreshness($data);
        return response()->json(['status' => 'success', 'data' => $data]);
    }

    public function activeTrips(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dashboard_scope' => ['nullable', 'in:standard'],
        ]);
        $limit = max(1, min((int) $request->query('limit', 100), 200));
        if (!$this->settingsService->isDriverMobileAppEnabled()) {
            return response()->json(['status' => 'success', 'data' => [
                'tracking_enabled' => false, 'items' => [], 'total' => 0,
                'with_position' => 0, 'generated_at' => now()->utc()->toIso8601String(),
            ]]);
        }
        return response()->json([
            'status' => 'success',
            'data' => $this->observability->activeTrips($limit, $validated['dashboard_scope'] ?? null),
        ]);
    }

    public function routeReplay(Request $request, Booking $booking): JsonResponse
    {
        $itemId = $this->validatedItemId($request, $booking, true);
        if (!$this->settingsService->isDriverMobileAppEnabled()) {
            return response()->json(['status' => 'success', 'data' => [
                'booking_id' => (string) $booking->id,
                'booking_item_id' => $itemId,
                'assignment_id' => null,
                'assignment_count' => 0,
                'segments' => [],
                'handoffs' => [],
                'total_points' => 0,
                'returned_points' => 0,
                'truncated' => false,
                'quality' => [
                    'scope' => 'returned_page', 'gap_threshold_seconds' => 0,
                    'gap_count' => 0, 'invalid_coordinate_count' => 0,
                    'inaccurate_point_count' => 0, 'implausible_speed_count' => 0,
                    'longest_gap_seconds' => 0, 'operational_distance_km' => 0,
                ],
                'pricing_effect' => 'none',
                'governance' => [
                    'retention_status' => 'not_configured', 'retention_days' => null,
                    'export_max_points' => 0, 'can_export' => false,
                ],
                'next_cursor' => null,
                'generated_at' => now()->utc()->toIso8601String(),
            ]]);
        }
        $limit = max(20, min((int) $request->query('limit', 500), 1000));
        $data = $this->observability->routeReplay($booking, $itemId, $request->query('cursor'), $limit);
        $data['governance']['can_export'] = (bool) $request->user()?->can('bookings.tracking_export')
            && $data['governance']['retention_status'] === 'configured';
        $this->logReplayAccess($request, $booking, $itemId, 'booking_route_replay_viewed', [
            'cursor_present' => $request->filled('cursor'),
            'returned_points' => $data['returned_points'],
        ]);
        return response()->json(['status' => 'success', 'data' => $data]);
    }

    public function generateOperationalEstimate(Request $request, Booking $booking): JsonResponse
    {
        $itemId = $this->validatedItemId($request, $booking, true);
        $validated = $request->validate([
            'purpose' => ['required', 'in:operational_gap_estimate'],
            'provider' => ['required', 'in:auto,osrm,google'],
            'idempotency_key' => ['required', 'uuid'],
            'metered_cost_confirmed' => ['required', 'boolean'],
        ]);
        $item = $booking->bookingItems()->whereKey($itemId)->firstOrFail();

        try {
            $estimate = $this->routeProviderGateway->estimate(
                $item,
                $validated['purpose'],
                $validated['provider'],
                $validated['idempotency_key'],
                (bool) $validated['metered_cost_confirmed'],
            );
            return response()->json(['status' => 'success', 'data' => $estimate]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An operational route estimate is not available right now.',
                'error_code' => 'ROUTE_ESTIMATE_UNAVAILABLE',
            ], 503);
        }
    }

    public function exportRouteReplay(Request $request, Booking $booking): StreamedResponse
    {
        abort_unless($this->settingsService->isDriverMobileAppEnabled(), 404);
        $itemId = $this->validatedItemId($request, $booking, true);
        abort_unless(config('booking_observability.route_retention_days'), 409, 'Route export is disabled until a retention policy is configured.');
        $limit = max(1, (int) config('booking_observability.route_export_max_points', 10000));
        $data = $this->observability->routeReplay($booking, $itemId, null, $limit);
        abort_if($data['truncated'], 422, 'Route export exceeds the configured point limit. Narrow the governed export scope.');
        $this->logReplayAccess($request, $booking, $itemId, 'booking_route_replay_exported', [
            'returned_points' => $data['returned_points'],
            'format' => 'csv',
        ], false);
        $rows = collect($data['segments'])->flatMap(fn (array $segment) => collect($segment['points'])->map(
            fn (array $point) => [
                $segment['phase'], $segment['assignment_id'], $point['id'], $point['sequence'],
                $point['tracking_phase'], $point['assignment_id'], implode('|', $point['quality_flags']),
                $point['latitude'], $point['longitude'], $point['recorded_at'], $point['accuracy_m'],
                $point['speed_kph'], $point['heading_degrees'],
            ]
        ));
        $filename = 'booking-route-' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $booking->booking_number) . '.csv';

        return response()->streamDownload(function () use ($rows): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['phase', 'assignment_id', 'point_id', 'sequence', 'tracking_phase', 'point_assignment_id', 'quality_flags', 'latitude', 'longitude', 'recorded_at', 'accuracy_m', 'speed_kph', 'heading_degrees']);
            foreach ($rows as $row) {
                fputcsv($stream, $row);
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function communications(Request $request, Booking $booking): JsonResponse
    {
        $itemId = $this->validatedItemId($request, $booking, false);
        return response()->json(['status' => 'success', 'data' => $this->observability->communications($booking, $itemId)]);
    }

    public function documents(Request $request, Booking $booking): JsonResponse
    {
        $itemId = $this->validatedItemId($request, $booking, false);
        return response()->json(['status' => 'success', 'data' => $this->observability->documents($booking, $itemId)]);
    }

    private function validatedItemId(Request $request, Booking $booking, bool $required): ?string
    {
        $rules = $required ? ['required', 'uuid'] : ['nullable', 'uuid'];
        $itemId = $request->validate(['booking_item_id' => $rules])['booking_item_id'] ?? null;
        if ($itemId && !$booking->bookingItems()->whereKey($itemId)->exists()) {
            abort(422, 'Selected booking item does not belong to this booking');
        }
        return $itemId;
    }

    private function logReplayAccess(
        Request $request,
        Booking $booking,
        string $itemId,
        string $action,
        array $details,
        bool $throttle = true
    ): void {
        $userId = $request->user()?->id;
        $cacheKey = implode(':', ['booking-route-access', $action, $userId ?: 'system', $booking->id, $itemId]);
        if ($throttle && !Cache::add($cacheKey, true, now()->addSeconds(
            max(1, (int) config('booking_observability.access_log_throttle_seconds', 300))
        ))) return;

        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'entity' => 'Booking',
            'entity_id' => $booking->id,
            'timestamp' => now('UTC'),
            'details' => [...$details, 'booking_item_id' => $itemId],
        ]);
    }
}
