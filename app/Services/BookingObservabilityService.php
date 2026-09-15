<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\Booking\BookingDispatch;
use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Booking\BookingActivity;
use App\Models\Sms\SmsMessage;
use App\Models\AuditLog;
use App\Models\Driver\RoutePoint;
use App\Models\Driver\DriverSession;
use App\Models\DriverAssignment;
use App\Models\Finance\FinancialAuditEvent;
use App\Models\Invoice;
use App\Services\Driver\RouteEvidenceService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BookingObservabilityService
{
    public function __construct(private readonly RouteEvidenceService $routeEvidence) {}

    public function trace(Booking $booking, ?string $bookingItemId, ?string $cursor = null, int $limit = 50, string $filter = 'all'): array
    {
        $decodedCursor = $cursor ? $this->decodeCursor($cursor) : null;
        $query = AuditLog::query()
            ->with('user:id,first_name,last_name')
            ->where(function ($scope) use ($booking, $bookingItemId) {
                $scope->where(function ($bookingEvents) use ($booking, $bookingItemId) {
                    $bookingEvents->where('entity', 'Booking')
                        ->where('entity_id', $booking->id);
                    if ($bookingItemId) {
                        $bookingEvents->where(function ($itemScope) use ($bookingItemId) {
                            $itemScope->whereNull('details->booking_item_id')
                                ->orWhere('details->booking_item_id', $bookingItemId);
                        });
                    }
                });
                if ($bookingItemId) {
                    $scope->orWhere(function ($itemEvents) use ($bookingItemId) {
                        $itemEvents->where('entity', 'BookingItem')
                            ->where('entity_id', $bookingItemId);
                    });
                }
            });

        if ($decodedCursor) {
            $query->where('timestamp', '<=', $decodedCursor[0]);
        }

        $logs = $query->orderByDesc('timestamp')->orderByDesc('id')->limit($limit + 1)->get();
        $events = $logs->map(fn(AuditLog $log): array => $this->mapAuditEvent($booking, $log));

        $financialQuery = FinancialAuditEvent::query()
            ->with('performedBy:id,first_name,last_name')
            ->where('booking_id', $booking->id);
        if ($decodedCursor) {
            $financialQuery->where('occurred_at', '<=', $decodedCursor[0]);
        }
        $financialEvents = $financialQuery->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit($limit + 1)->get()
            ->map(fn(FinancialAuditEvent $event): array => $this->mapFinancialEvent($booking, $event));

        $tripEvents = $bookingItemId
            ? $this->tripMilestoneEvents($booking, $bookingItemId, $decodedCursor, $limit + 1)
            : collect();
        $communicationEvents = $this->communicationItems($booking, $bookingItemId)
            ->when(!$bookingItemId, fn(Collection $items) => $items->whereNull('booking_item_id'))
            ->map(fn (array $event): array => $this->mapCommunicationTraceEvent($booking, $event));
        $documentEvents = $this->documentItems($booking, $bookingItemId)
            ->when(!$bookingItemId, fn(Collection $items) => $items->whereNull('booking_item_id'))
            ->map(fn (array $event): array => $this->mapDocumentTraceEvent($booking, $event));

        $events = $events->concat($financialEvents)->concat($tripEvents)
            ->concat($communicationEvents)->concat($documentEvents)
            ->map(fn (array $event): array => $this->withTraceIntegrity($event))
            ->unique('deduplication_key')
            ->filter(fn (array $event): bool => $this->matchesTraceFilter($event, $filter))
            ->when($decodedCursor, fn(Collection $items) => $items->filter(
                fn (array $event): bool => $this->isBeforeCursor($event, $decodedCursor)
            ))
            ->sortByDesc(fn (array $event): string => $event['occurred_at'] . '|' . $event['id'])
            ->values();
        $hasMore = $events->count() > $limit;
        $events = $events->take($limit)->values();
        $last = $events->last();

        return [
            'booking_id' => (string) $booking->id,
            'selected_booking_item_id' => $bookingItemId,
            'selected_filter' => $filter,
            'events' => $events->all(),
            'next_cursor' => $hasMore && $last
                ? $this->encodeCursor($last['occurred_at'], $last['id'])
                : null,
            'generated_at' => now()->utc()->toIso8601String(),
        ];
    }

    public function communications(Booking $booking, ?string $bookingItemId): array
    {
        return [
            'booking_id' => (string) $booking->id,
            'selected_booking_item_id' => $bookingItemId,
            'items' => $this->communicationItems($booking, $bookingItemId)->sortByDesc('occurred_at')->values()->all(),
            'sources' => [
                'invoice_delivery' => 'connected',
                'dispatch_delivery' => 'connected',
                'transactional_sms' => 'connected',
                'booking_activities' => 'connected',
                'notification_logs' => 'unavailable_no_booking_link',
            ],
            'generated_at' => now()->utc()->toIso8601String(),
        ];
    }

    public function documents(Booking $booking, ?string $bookingItemId): array
    {
        return [
            'booking_id' => (string) $booking->id,
            'selected_booking_item_id' => $bookingItemId,
            'items' => $this->documentItems($booking, $bookingItemId)->sortByDesc('created_at')->values()->all(),
            'sources' => [
                'invoices' => 'connected',
                'payment_receipts' => 'connected',
                'dispatch_documents' => 'metadata_only',
            ],
            'generated_at' => now()->utc()->toIso8601String(),
        ];
    }

    public function trackingSummary(Booking $booking, string $bookingItemId): array
    {
        $bookingItem = $booking->bookingItems()->whereKey($bookingItemId)->first();
        $assignment = $this->assignmentForItem($booking, $bookingItemId);
        $latest = $assignment ? $this->routeQuery($assignment)->latest('recorded_at')->first() : null;
        $totalPoints = $assignment ? $this->routeQuery($assignment)->count() : 0;
        $lastReportedAt = $latest?->recorded_at?->utc();
        $driver = $assignment?->driver;
        $distanceEvidence = $assignment
            ? $this->routeEvidence->calculate(
                $assignment->routePoints()->orderBy('recorded_at')->orderBy('id')->get(),
                $assignment->trip_started_at?->copy()->utc(),
                ($assignment->trip_completed_at ?? $assignment->actual_end ?? now('UTC'))->copy()->utc()
            )
            : $this->routeEvidence->calculate(collect(), null, null);

        return [
            'booking_id' => (string) $booking->id,
            'booking_item_id' => $bookingItemId,
            'assignment_id' => $assignment?->id,
            'driver_id' => $assignment?->driver_id,
            'enabled' => (bool) $assignment,
            'is_online' => (bool) ($driver?->is_online ?? false),
            'freshness' => $this->freshness($lastReportedAt, (bool) ($driver?->is_online ?? false)),
            'last_reported_at' => $lastReportedAt?->toIso8601String(),
            'active_position' => $latest ? $this->mapPosition($latest) : null,
            'trip_phase' => $assignment?->trip_phase?->value ?? ($assignment?->trip_phase ? (string) $assignment->trip_phase : null),
            'total_points' => $totalPoints,
            'route_truncated' => false,
            'has_replay' => $totalPoints > 0,
            'distance_evidence' => $distanceEvidence,
            'completion_evidence' => data_get($bookingItem?->metadata, 'driver_route_evidence.completion'),
            'pricing_effect' => 'none',
            'generated_at' => now()->utc()->toIso8601String(),
        ];
    }

    public function activeTrips(int $limit = 100, ?string $dashboardScope = null): array
    {
        $assignments = DriverAssignment::query()
            ->with([
                'booking:id,booking_number,status',
                'bookingItem:id,booking_id,trip_number,vehicle_id',
                'bookingItem.vehicle:id,title,license_plate',
                'driver.user:id,first_name,last_name',
            ])
            ->where('status', 'active')
            ->where('trip_phase', 'in_progress')
            ->whereHas('bookingItem', function ($query) {
                $query->whereNull('completed_at')
                    ->whereNotIn('status', ['completed', 'cancelled', 'rejected'])
                    ->whereRaw("(booking_items.to_date + COALESCE(booking_items.to_time, '23:59:59')::time) >= ?", [now()]);
            })
            ->whereHas('booking', function ($query) {
                $query->where(function ($paymentQuery) {
                    $paymentQuery->where('payment_status', 'paid')
                        ->orWhereIn('payment_collection_status', ['driver_collected', 'online_paid', 'paid']);
                });
            })
            ->when($dashboardScope === 'standard', function ($query) {
                $query->whereHas('booking', fn($bookingQuery) => $bookingQuery->whereNull('corporate_account_id'));
            })
            ->latest('updated_at')
            ->limit(max(1, min($limit, 200)))
            ->get();

        $rankedPoints = RoutePoint::query()
            ->select(['id', 'assignment_id', 'latitude', 'longitude', 'accuracy', 'speed', 'heading', 'recorded_at'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY assignment_id ORDER BY recorded_at DESC, id DESC) AS point_rank')
            ->whereIn('assignment_id', $assignments->pluck('id'));
        $latestPoints = DB::query()->fromSub($rankedPoints, 'ranked_route_points')
            ->where('point_rank', 1)
            ->get()
            ->keyBy('assignment_id');

        $items = $assignments->map(function (DriverAssignment $assignment) use ($latestPoints): array {
            $point = $latestPoints->get($assignment->id);
            $recordedAt = $point?->recorded_at ? Carbon::parse($point->recorded_at)->utc() : null;
            $driverName = trim((string) (($assignment->driver?->user?->first_name ?? '') . ' ' . ($assignment->driver?->user?->last_name ?? '')));

            return [
                'assignment_id' => (string) $assignment->id,
                'booking_id' => (string) $assignment->booking_id,
                'booking_item_id' => $assignment->booking_item_id ? (string) $assignment->booking_item_id : null,
                'booking_number' => $assignment->booking?->booking_number,
                'trip_number' => $assignment->bookingItem?->trip_number,
                'driver_id' => $assignment->driver_id ? (string) $assignment->driver_id : null,
                'driver_name' => $driverName !== '' ? $driverName : ($assignment->driver?->code ?? 'Assigned driver'),
                'vehicle_name' => $assignment->bookingItem?->vehicle?->title,
                'vehicle_registration' => $assignment->bookingItem?->vehicle?->license_plate,
                'trip_phase' => $assignment->trip_phase?->value ?? ($assignment->trip_phase ? (string) $assignment->trip_phase : null),
                'freshness' => $this->freshness($recordedAt, (bool) ($assignment->driver?->is_online ?? false)),
                'is_online' => (bool) ($assignment->driver?->is_online ?? false),
                'last_reported_at' => $recordedAt?->toIso8601String(),
                'position' => $point ? [
                    'latitude' => (float) $point->latitude,
                    'longitude' => (float) $point->longitude,
                    'recorded_at' => $recordedAt?->toIso8601String(),
                    'accuracy_m' => $point->accuracy !== null ? (float) $point->accuracy : null,
                    'speed_kph' => $point->speed !== null ? (float) $point->speed : null,
                    'heading_degrees' => $point->heading !== null ? (float) $point->heading : null,
                ] : null,
            ];
        })->values();

        return [
            'items' => $items->all(),
            'total' => $items->count(),
            'with_position' => $items->whereNotNull('position')->count(),
            'generated_at' => now()->utc()->toIso8601String(),
        ];
    }

    public function routeReplay(Booking $booking, string $bookingItemId, ?string $cursor = null, int $limit = 500): array
    {
        $assignments = DriverAssignment::query()->where('booking_id', $booking->id)
            ->where('booking_item_id', $bookingItemId)
            ->orderBy('created_at')->orderBy('id')->get();
        [$query, $sessionAssignmentIds] = $this->routeQueryForAssignments($assignments);
        $totalPoints = (clone $query)->count();
        $previousPoint = null;

        if ($cursor && ($decoded = $this->decodeCursor($cursor))) {
            [$recordedAt, $id] = $decoded;
            $previousPoint = (clone $query)->where(fn($q) => $q->where('recorded_at', '<', $recordedAt)
                ->orWhere(fn($same) => $same->where('recorded_at', $recordedAt)->where('id', '<=', $id)))
                ->orderByDesc('recorded_at')->orderByDesc('id')->first();
            $query->where(fn($q) => $q->where('recorded_at', '>', $recordedAt)
                ->orWhere(fn($same) => $same->where('recorded_at', $recordedAt)->where('id', '>', $id)));
        }

        $points = $query->orderBy('recorded_at')->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $points->count() > $limit;
        $points = $points->take($limit)->values();
        $assignmentsById = $assignments->keyBy(fn(DriverAssignment $assignment) => (string) $assignment->id);
        [$mapped, $segments, $quality] = $this->segmentRoutePoints(
            $points,
            $assignmentsById,
            $sessionAssignmentIds,
            $previousPoint
        );
        $last = $points->last();
        $activeAssignment = $this->assignmentForItem($booking, $bookingItemId);

        return [
            'booking_id' => (string) $booking->id,
            'booking_item_id' => $bookingItemId,
            'assignment_id' => $activeAssignment?->id,
            'assignment_count' => $assignments->count(),
            'segments' => $segments->all(),
            'total_points' => $totalPoints,
            'returned_points' => $mapped->count(),
            'truncated' => $hasMore,
            'quality' => $quality,
            'pricing_effect' => 'none',
            'governance' => [
                'retention_status' => config('booking_observability.route_retention_days')
                    ? 'configured' : 'not_configured',
                'retention_days' => config('booking_observability.route_retention_days'),
                'export_max_points' => (int) config('booking_observability.route_export_max_points', 10000),
            ],
            'next_cursor' => $hasMore && $last ? $this->encodeCursor($last->recorded_at->utc()->toIso8601String(), (string) $last->id) : null,
            'generated_at' => now()->utc()->toIso8601String(),
        ];
    }

    private function assignmentForItem(Booking $booking, string $bookingItemId): ?DriverAssignment
    {
        return DriverAssignment::with('driver')->where('booking_id', $booking->id)
            ->where('booking_item_id', $bookingItemId)
            ->orderByRaw("CASE WHEN status = 'active' AND (trip_phase IS NULL OR trip_phase NOT IN ('completed', 'declined')) THEN 0 ELSE 1 END")
            ->latest('updated_at')
            ->first();
    }

    private function routeQuery(DriverAssignment $assignment)
    {
        $sessionIds = DriverSession::query()
            ->where('assignment_id', $assignment->id)
            ->pluck('id');
        $windowStart = $assignment->trip_started_at ?? $assignment->confirmed_at ?? $assignment->created_at;
        $windowEnd = $assignment->trip_completed_at ?? now('UTC');

        return RoutePoint::query()->where(function ($query) use ($assignment, $sessionIds, $windowStart, $windowEnd) {
            $query->where('assignment_id', $assignment->id);
            if ($sessionIds->isNotEmpty() && $windowStart) {
                $query->orWhere(function ($gapQuery) use ($sessionIds, $windowStart, $windowEnd) {
                    $gapQuery->whereNull('assignment_id')
                        ->whereIn('session_id', $sessionIds)
                        ->whereBetween('recorded_at', [$windowStart, $windowEnd]);
                });
            }
        });
    }

    private function routeQueryForAssignments(Collection $assignments): array
    {
        if ($assignments->isEmpty()) {
            return [RoutePoint::query()->whereRaw('1 = 0'), collect()];
        }

        $sessionAssignmentIds = DriverSession::query()->whereIn('assignment_id', $assignments->pluck('id'))
            ->pluck('assignment_id', 'id');
        $query = RoutePoint::query()->where(function ($scope) use ($assignments, $sessionAssignmentIds) {
            $scope->whereIn('assignment_id', $assignments->pluck('id'));
            foreach ($assignments as $assignment) {
                $sessionIds = $sessionAssignmentIds->filter(
                    fn($assignmentId) => (string) $assignmentId === (string) $assignment->id
                )->keys();
                $windowStart = $assignment->trip_started_at ?? $assignment->confirmed_at ?? $assignment->created_at;
                $windowEnd = $assignment->trip_completed_at ?? $assignment->actual_end ?? now('UTC');
                if ($sessionIds->isNotEmpty() && $windowStart) {
                    $scope->orWhere(function ($gapQuery) use ($sessionIds, $windowStart, $windowEnd) {
                        $gapQuery->whereNull('assignment_id')->whereIn('session_id', $sessionIds)
                            ->whereBetween('recorded_at', [$windowStart, $windowEnd]);
                    });
                }
            }
        });

        return [$query, $sessionAssignmentIds];
    }

    private function phase(RoutePoint $point, ?DriverAssignment $assignment): string
    {
        if (!$assignment)
            return 'unknown';
        if (!$assignment?->confirmed_at || $point->recorded_at->lt($assignment->confirmed_at))
            return 'before_accept';
        if (!$assignment->pickup_arrived_at || $point->recorded_at->lt($assignment->pickup_arrived_at))
            return 'accepted_to_pickup';
        if ($assignment->trip_completed_at && $point->recorded_at->gt($assignment->trip_completed_at))
            return 'post_dropoff';
        return 'pickup_to_dropoff';
    }

    private function segmentRoutePoints(
        Collection $points,
        Collection $assignmentsById,
        Collection $sessionAssignmentIds,
        ?RoutePoint $previousPoint = null
    ): array
    {
        $mapped = collect();
        $segments = collect();
        $current = null;
        $previousAssignmentId = $previousPoint ? $this->pointAssignmentId($previousPoint, $sessionAssignmentIds) : null;
        $quality = [
            'scope' => 'returned_page',
            'gap_threshold_seconds' => RouteEvidenceService::GAP_THRESHOLD_SECONDS,
            'gap_count' => 0,
            'invalid_coordinate_count' => 0,
            'inaccurate_point_count' => 0,
            'implausible_speed_count' => 0,
            'longest_gap_seconds' => 0,
            'operational_distance_km' => 0.0,
        ];

        foreach ($points as $index => $point) {
            $assignmentId = $this->pointAssignmentId($point, $sessionAssignmentIds);
            $assignment = $assignmentId ? $assignmentsById->get($assignmentId) : null;
            $gapSeconds = $previousPoint
                ? max(0, $previousPoint->recorded_at->diffInSeconds($point->recorded_at, false))
                : 0;
            $flags = [];
            $latitude = (float) $point->latitude;
            $longitude = (float) $point->longitude;
            $validCoordinate = $latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180;
            if (!$validCoordinate) {
                $flags[] = 'invalid_coordinate';
                $quality['invalid_coordinate_count']++;
            }
            if ($point->accuracy !== null && (float) $point->accuracy > 100) {
                $flags[] = 'low_accuracy';
                $quality['inaccurate_point_count']++;
            }
            if ($point->speed !== null && (float) $point->speed > 180) {
                $flags[] = 'implausible_speed';
                $quality['implausible_speed_count']++;
            }
            $previousCoordinateValid = $previousPoint
                && (float) $previousPoint->latitude >= -90 && (float) $previousPoint->latitude <= 90
                && (float) $previousPoint->longitude >= -180 && (float) $previousPoint->longitude <= 180;
            $segmentDistance = $previousCoordinateValid && $validCoordinate
                ? $this->haversineKm(
                    (float) $previousPoint->latitude,
                    (float) $previousPoint->longitude,
                    $latitude,
                    $longitude
                )
                : 0.0;
            $implausibleMovement = $gapSeconds > 0
                && $gapSeconds <= $quality['gap_threshold_seconds']
                && (($segmentDistance / $gapSeconds) * 3600) > RouteEvidenceService::MAX_PLAUSIBLE_SPEED_KPH;
            if ($implausibleMovement) {
                if (!in_array('implausible_speed', $flags, true)) {
                    $quality['implausible_speed_count']++;
                }
                $flags[] = 'implausible_movement';
            }
            if ($gapSeconds > $quality['gap_threshold_seconds']) {
                $flags[] = 'gap_before';
                $quality['gap_count']++;
                $quality['longest_gap_seconds'] = max($quality['longest_gap_seconds'], $gapSeconds);
            }

            $phase = $this->phase($point, $assignment);
            $mappedPoint = [
                'id' => (string) $point->id,
                'sequence' => $index,
                'tracking_phase' => $phase,
                'assignment_id' => $assignmentId,
                'quality_flags' => $flags,
                ...$this->mapPosition($point),
            ];
            $mapped->push($mappedPoint);

            $assignmentChanged = $previousPoint && $previousAssignmentId !== $assignmentId;
            $startsSegment = !$current || $current['phase'] !== $phase || $assignmentChanged
                || $gapSeconds > $quality['gap_threshold_seconds'] || $implausibleMovement;
            if ($startsSegment) {
                if ($current)
                    $segments->push($current);
                $current = [
                    'phase' => $phase,
                    'assignment_id' => $assignmentId,
                    'boundary' => $assignmentChanged ? 'replacement_assignment' : null,
                    'continues_previous_segment' => $index === 0 && $previousPoint
                        && !$assignmentChanged && $gapSeconds <= $quality['gap_threshold_seconds']
                        && $this->phase($previousPoint, $assignment) === $phase,
                    'started_at' => $mappedPoint['recorded_at'],
                    'ended_at' => $mappedPoint['recorded_at'],
                    'gap_before_seconds' => $gapSeconds ?: null,
                    'distance_km' => 0.0,
                    'quality_flags' => $flags,
                    'points' => [],
                ];
            }

            if ($previousPoint && !$assignmentChanged && $previousCoordinateValid && $validCoordinate
                && $gapSeconds <= $quality['gap_threshold_seconds'] && !$implausibleMovement) {
                $current['distance_km'] += $segmentDistance;
                $quality['operational_distance_km'] += $segmentDistance;
            }
            $current['ended_at'] = $mappedPoint['recorded_at'];
            $current['quality_flags'] = array_values(array_unique([...$current['quality_flags'], ...$flags]));
            $current['points'][] = $mappedPoint;
            $previousPoint = $point;
            $previousAssignmentId = $assignmentId;
        }
        if ($current)
            $segments->push($current);

        $segments = $segments->map(function (array $segment): array {
            $segment['distance_km'] = round($segment['distance_km'], 3);
            return $segment;
        })->values();
        $quality['operational_distance_km'] = round($quality['operational_distance_km'], 3);

        return [$mapped, $segments, $quality];
    }

    private function pointAssignmentId(RoutePoint $point, Collection $sessionAssignmentIds): ?string
    {
        $assignmentId = $point->assignment_id ?? $sessionAssignmentIds->get($point->session_id);
        return $assignmentId ? (string) $assignmentId : null;
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0088;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;
        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
    }

    private function mapPosition(RoutePoint $point): array
    {
        return [
            'latitude' => (float) $point->latitude,
            'longitude' => (float) $point->longitude,
            'recorded_at' => $point->recorded_at?->utc()->toIso8601String(),
            'accuracy_m' => $point->accuracy !== null ? (float) $point->accuracy : null,
            'speed_kph' => $point->speed !== null ? (float) $point->speed : null,
            'heading_degrees' => $point->heading !== null ? (float) $point->heading : null,
        ];
    }

    private function freshness(?Carbon $reportedAt, bool $online): string
    {
        if (!$reportedAt)
            return 'never_reported';
        if (!$online)
            return 'offline';
        $age = $reportedAt->diffInSeconds(now(), true);
        return $age <= 120 ? 'live' : ($age <= 300 ? 'delayed' : 'stale');
    }

    private function mapAuditEvent(Booking $booking, AuditLog $log): array
    {
        $details = is_array($log->details) ? $log->details : [];
        $itemId = $log->entity === 'BookingItem'
            ? (string) $log->entity_id
            : ($details['booking_item_id'] ?? null);
        $action = (string) $log->action;
        $actorSnapshot = $details['actor_display_snapshot'] ?? null;
        $actorName = $actorSnapshot ?: ($log->user
            ? trim((string) $log->user->first_name . ' ' . (string) $log->user->last_name)
            : 'System');
        $title = match ($action) {
            'booking_lifecycle_transitioned' => 'Booking lifecycle changed',
            'booking_item_lifecycle_transitioned' => 'Trip lifecycle changed',
            'booking_force_completed' => 'Booking administratively completed',
            'corporate_booking_completed' => 'Corporate booking completed',
            'corporate_contractual_distance_overridden' => 'Contractual distance decision recorded',
            default => str($action)->replace('_', ' ')->title()->toString(),
        };
        $source = str_contains($action, 'lifecycle') ? 'lifecycle'
            : (str_contains($action, 'corporate') ? 'approval' : 'system');
        $metadata = collect($details)->only([
            'stage',
            'source',
            'transition_source',
            'reason',
            'booking_number',
        ])->all();

        return [
            'id' => 'audit:' . $log->id,
            'booking_id' => (string) $booking->id,
            'booking_item_id' => $itemId ?: null,
            'occurred_at' => $log->timestamp->utc()->toIso8601String(),
            'source' => $source,
            'event_type' => $action,
            'title' => $title,
            'description' => null,
            'actor' => [
                'id' => $log->user_id ? (string) $log->user_id : null,
                'name' => $actorName !== '' ? $actorName : 'System',
                'type' => $log->user_id ? 'user' : 'system',
            ],
            'from_status' => $details['from_status'] ?? null,
            'to_status' => $details['to_status'] ?? ($details['stage'] ?? null),
            'severity' => str_contains($action, 'force') ? 'warning'
                : (str_contains($action, 'completed') ? 'success' : 'info'),
            'correlation_id' => $details['correlation_id'] ?? $details['idempotency_key'] ?? null,
            'metadata' => $metadata,
            'evidence' => null,
            'actor_display_snapshot' => $actorSnapshot,
        ];
    }

    private function mapFinancialEvent(Booking $booking, FinancialAuditEvent $event): array
    {
        $metadata = collect(is_array($event->metadata) ? $event->metadata : [])->only([
            'method',
            'stage',
            'purpose',
            'received_via',
            'invoice_number',
            'reason',
        ])->all();
        if ($event->amount !== null) {
            $metadata['amount'] = (float) $event->amount;
        }
        $actorSnapshot = ($event->metadata ?? [])['actor_display_snapshot'] ?? null;
        $actorName = $actorSnapshot ?: ($event->performedBy
            ? trim((string) $event->performedBy->first_name . ' ' . (string) $event->performedBy->last_name)
            : 'System');

        return [
            'id' => 'finance:' . $event->id,
            'booking_id' => (string) $booking->id,
            'booking_item_id' => null,
            'occurred_at' => $event->occurred_at->utc()->toIso8601String(),
            'source' => 'finance',
            'event_type' => (string) $event->event_type,
            'title' => str((string) $event->event_type)->replace('_', ' ')->title()->toString(),
            'description' => null,
            'actor' => [
                'id' => $event->performed_by ? (string) $event->performed_by : null,
                'name' => $actorName !== '' ? $actorName : 'System',
                'type' => $event->performed_by ? 'user' : 'system',
            ],
            'from_status' => $event->from_status,
            'to_status' => $event->to_status,
            'severity' => in_array($event->event_type, ['disputed', 'refund', 'security_deposit_refunded'], true) ? 'warning' : 'info',
            'correlation_id' => implode(':', ['finance', $event->subject_type, $event->subject_id, $event->event_type]),
            'metadata' => $metadata,
            'evidence' => ['section' => 'payments', 'label' => 'View payments'],
            'actor_display_snapshot' => $actorSnapshot,
        ];
    }

    private function tripMilestoneEvents(Booking $booking, string $bookingItemId, ?array $cursor, int $limit): Collection
    {
        $assignments = DriverAssignment::query()
            ->with(['driver.user:id,first_name,last_name', 'assignedBy:id,first_name,last_name', 'confirmedBy:id,first_name,last_name'])
            ->where('booking_id', $booking->id)
            ->where('booking_item_id', $bookingItemId)
            ->when($cursor, fn($query) => $query->where('created_at', '<=', $cursor[0]))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();

        return $assignments->flatMap(function (DriverAssignment $assignment) use ($booking, $bookingItemId): array {
            $milestones = [
                'assigned' => [$assignment->created_at, 'Driver assigned'],
                'assignment_confirmed' => [$assignment->confirmed_at, 'Assignment confirmed'],
                'pickup_arrived' => [$assignment->pickup_arrived_at, 'Driver arrived at pickup'],
                'trip_started' => [$assignment->trip_started_at ?? $assignment->actual_start, 'Trip started'],
                'trip_completed' => [$assignment->trip_completed_at ?? $assignment->actual_end, 'Trip completed'],
            ];
            $driverName = trim((string) (($assignment->driver?->user?->first_name ?? '') . ' ' . ($assignment->driver?->user?->last_name ?? '')));

            return collect($milestones)->filter(fn(array $milestone) => $milestone[0] !== null)
                ->map(function (array $milestone, string $type) use ($assignment, $booking, $bookingItemId, $driverName): array {
                    $assignedByName = trim((string) (($assignment->assignedBy?->first_name ?? '') . ' ' . ($assignment->assignedBy?->last_name ?? '')));
                    $confirmedByName = trim((string) (($assignment->confirmedBy?->first_name ?? '') . ' ' . ($assignment->confirmedBy?->last_name ?? '')));
                    $isAssignmentEvent = $type === 'assigned';
                    $isConfirmationEvent = $type === 'assignment_confirmed';
                    $actorId = $isAssignmentEvent ? $assignment->assigned_by
                        : ($isConfirmationEvent ? $assignment->confirmed_by : $assignment->driver_id);
                    $actorSnapshot = $isAssignmentEvent ? $assignment->assigned_by_name_snapshot
                        : ($isConfirmationEvent ? $assignment->confirmed_by_name_snapshot : $assignment->driver_name_snapshot);
                    $actorName = $actorSnapshot ?: ($isAssignmentEvent && $assignedByName !== '' ? $assignedByName
                        : ($isConfirmationEvent && $confirmedByName !== '' ? $confirmedByName : ($driverName !== '' ? $driverName : 'System')));

                    return [
                        'id' => 'assignment:' . $assignment->id . ':' . $type,
                        'booking_id' => (string) $booking->id,
                        'booking_item_id' => $bookingItemId,
                        'occurred_at' => Carbon::parse($milestone[0])->utc()->toIso8601String(),
                        'source' => 'trip',
                        'event_type' => $type,
                        'title' => $milestone[1],
                        'description' => $driverName !== '' ? $driverName : null,
                        'actor' => [
                            'id' => $actorId ? (string) $actorId : null,
                            'name' => $actorName,
                            'type' => ($isAssignmentEvent || $isConfirmationEvent) && $actorId ? 'user' : ($assignment->driver_id ? 'driver' : 'system'),
                        ],
                        'from_status' => null,
                        'to_status' => $type,
                        'severity' => $type === 'trip_completed' ? 'success' : 'info',
                        'correlation_id' => implode(':', ['assignment', $assignment->id, $type]),
                        'metadata' => array_filter([
                            'assignment_status' => (string) $assignment->status,
                            'latitude' => match ($type) {
                                'assignment_confirmed' => $assignment->accept_latitude,
                                'pickup_arrived' => $assignment->pickup_arrival_latitude,
                                'trip_started' => $assignment->trip_start_latitude,
                                'trip_completed' => $assignment->final_latitude,
                                default => null,
                            },
                            'longitude' => match ($type) {
                                'assignment_confirmed' => $assignment->accept_longitude,
                                'pickup_arrived' => $assignment->pickup_arrival_longitude,
                                'trip_started' => $assignment->trip_start_longitude,
                                'trip_completed' => $assignment->final_longitude,
                                default => null,
                            },
                        ], fn ($value) => $value !== null),
                        'evidence' => ['section' => 'tracking', 'label' => 'View trip tracking'],
                        'actor_display_snapshot' => $actorSnapshot,
                    ];
                })->values()->all();
        })->values();
    }

    private function communicationItems(Booking $booking, ?string $bookingItemId): Collection
    {
        $events = Invoice::query()->where('booking_id', $booking->id)
            ->whereNotNull('email_sent_at')->get()
            ->map(fn(Invoice $invoice): array => [
                'id' => 'invoice-email-' . $invoice->id,
                'booking_item_id' => null,
                'channel' => 'email',
                'direction' => 'outbound',
                'title' => 'Invoice ' . $invoice->invoice_number . ' sent',
                'status' => 'sent',
                'occurred_at' => $invoice->email_sent_at->utc()->toIso8601String(),
                'recipient' => $invoice->customer_email,
                'source' => 'invoice_delivery',
            ]);

        $dispatchEvents = BookingDispatch::query()->where('booking_id', $booking->id)
            ->when($bookingItemId, fn($query) => $query->where('booking_item_id', $bookingItemId))
            ->whereNotNull('trigger_delivered_at')->get()
            ->map(fn(BookingDispatch $dispatch): array => [
                'id' => 'dispatch-trigger-' . $dispatch->id,
                'booking_item_id' => $dispatch->booking_item_id ? (string) $dispatch->booking_item_id : null,
                'channel' => $dispatch->trigger_delivery_channel ?: 'system',
                'direction' => 'outbound',
                'title' => 'Dispatch instructions delivered',
                'status' => 'delivered',
                'occurred_at' => $dispatch->trigger_delivered_at->utc()->toIso8601String(),
                'recipient' => null,
                'source' => 'dispatch_delivery',
            ]);

        $smsEvents = SmsMessage::query()->where('booking_id', $booking->id)
            ->when($bookingItemId, fn($query) => $query->where(function ($scope) use ($bookingItemId) {
                $scope->whereNull('booking_item_id')->orWhere('booking_item_id', $bookingItemId);
            }))
            ->get()
            ->map(fn(SmsMessage $message): array => [
                'id' => 'sms-' . $message->id,
                'sms_message_id' => (string) $message->id,
                'booking_item_id' => $message->booking_item_id ? (string) $message->booking_item_id : null,
                'driver_assignment_id' => $message->driver_assignment_id ? (string) $message->driver_assignment_id : null,
                'channel' => 'sms',
                'direction' => 'outbound',
                'event_key' => $message->event_key,
                'title' => $message->event_key ? str_replace(['.', '_'], ' ', $message->event_key) : 'SMS message',
                'status' => $message->status,
                'provider_status' => $message->provider_status,
                'occurred_at' => ($message->triggered_at ?? $message->created_at)?->utc()->toIso8601String(),
                'recipient' => $this->maskRecipient((string) $message->normalized_recipient),
                'source' => $message->source ?: 'sms',
                'failure_reason' => $message->error_message,
                'segments' => (int) ($message->segments ?: 1),
                'total_cost' => $message->total_cost,
                'cost_currency' => $message->cost_currency,
            ]);

        $decisionEvents = BookingActivity::query()->where('booking_id', $booking->id)
            ->whereNull('sms_message_id')
            ->when($bookingItemId, fn($query) => $query->where(function ($scope) use ($bookingItemId) {
                $scope->whereNull('booking_item_id')->orWhere('booking_item_id', $bookingItemId);
            }))
            ->get()
            ->map(fn(BookingActivity $activity): array => [
                'id' => 'booking-activity-' . $activity->id,
                'sms_message_id' => null,
                'booking_item_id' => $activity->booking_item_id ? (string) $activity->booking_item_id : null,
                'driver_assignment_id' => $activity->driver_assignment_id ? (string) $activity->driver_assignment_id : null,
                'channel' => $activity->channel,
                'direction' => 'system',
                'event_key' => $activity->event_key,
                'title' => $activity->title,
                'status' => $activity->result_status,
                'provider_status' => null,
                'occurred_at' => $activity->event_at?->utc()->toIso8601String(),
                'recipient' => $activity->recipient_masked,
                'source' => $activity->source,
                'failure_reason' => $activity->detail,
                'segments' => null,
                'total_cost' => null,
                'cost_currency' => null,
            ]);

        return $events->concat($dispatchEvents)->concat($smsEvents)->concat($decisionEvents)
            ->when(!$bookingItemId, fn (Collection $items) => $items->whereNull('booking_item_id'))
            ->values();
    }

    private function maskRecipient(string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', $number);
        return $digits === '' ? null : str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }

    private function documentItems(Booking $booking, ?string $bookingItemId): Collection
    {
        $items = Invoice::query()->where('booking_id', $booking->id)->get()
            ->map(fn(Invoice $invoice): array => [
                'id' => 'invoice-' . $invoice->id,
                'booking_item_id' => null,
                'type' => 'invoice',
                'title' => 'Invoice ' . $invoice->invoice_number,
                'status' => $invoice->status,
                'created_at' => ($invoice->pdf_generated_at ?? $invoice->created_at)?->utc()->toIso8601String(),
                'reference' => $invoice->invoice_number,
                'download_url' => null,
            ]);

        $receipts = BookingPaymentReceipt::query()->where('booking_id', $booking->id)->get()
            ->map(fn(BookingPaymentReceipt $receipt): array => [
                'id' => 'receipt-' . $receipt->id,
                'booking_item_id' => null,
                'type' => 'payment_receipt',
                'title' => 'Payment receipt',
                'status' => 'recorded',
                'created_at' => $receipt->received_at?->utc()->toIso8601String(),
                'reference' => $receipt->reference,
                'download_url' => null,
            ]);

        $dispatchDocuments = BookingDispatch::query()->where('booking_id', $booking->id)
            ->when($bookingItemId, fn($query) => $query->where('booking_item_id', $bookingItemId))
            ->get()->flatMap(function (BookingDispatch $dispatch): array {
                return collect($dispatch->documents_generated ?? [])->map(function ($document, int $index) use ($dispatch): array {
                    $value = is_array($document) ? ($document['name'] ?? $document['title'] ?? 'Generated document') : $document;
                    return [
                        'id' => 'dispatch-document-' . $dispatch->id . '-' . $index,
                        'booking_item_id' => $dispatch->booking_item_id ? (string) $dispatch->booking_item_id : null,
                        'type' => 'dispatch_document',
                        'title' => basename((string) $value),
                        'status' => 'generated',
                        'created_at' => $dispatch->updated_at?->utc()->toIso8601String(),
                        'reference' => null,
                        'download_url' => null,
                    ];
                })->all();
            });

        return $items->concat($receipts)->concat($dispatchDocuments)
            ->filter(fn (array $document): bool => !empty($document['created_at']))
            ->values();
    }

    private function mapCommunicationTraceEvent(Booking $booking, array $event): array
    {
        return [
            'id' => 'communication:' . $event['id'],
            'booking_id' => (string) $booking->id,
            'booking_item_id' => $event['booking_item_id'],
            'occurred_at' => $event['occurred_at'],
            'source' => 'communication',
            'event_type' => $event['source'],
            'title' => $event['title'],
            'description' => null,
            'actor' => ['id' => null, 'name' => 'System', 'type' => 'system'],
            'from_status' => null,
            'to_status' => $event['status'],
            'severity' => 'info',
            'correlation_id' => 'communication:' . $event['id'],
            'metadata' => collect($event)->only(['channel', 'direction'])->all(),
            'evidence' => ['section' => 'communication', 'label' => 'View communication'],
        ];
    }

    private function mapDocumentTraceEvent(Booking $booking, array $event): array
    {
        return [
            'id' => 'document:' . $event['id'],
            'booking_id' => (string) $booking->id,
            'booking_item_id' => $event['booking_item_id'],
            'occurred_at' => $event['created_at'],
            'source' => 'document',
            'event_type' => $event['type'],
            'title' => $event['title'],
            'description' => null,
            'actor' => ['id' => null, 'name' => 'System', 'type' => 'system'],
            'from_status' => null,
            'to_status' => $event['status'],
            'severity' => 'info',
            'correlation_id' => 'document:' . $event['id'],
            'metadata' => collect($event)->only(['type', 'reference'])->filter()->all(),
            'evidence' => ['section' => 'documents', 'label' => 'View documents'],
        ];
    }

    private function matchesTraceFilter(array $event, string $filter): bool
    {
        return match ($filter) {
            'operations' => in_array($event['source'], ['lifecycle', 'trip'], true),
            'journey' => in_array($event['source'], ['trip', 'tracking'], true),
            'finance' => $event['source'] === 'finance',
            'communications' => $event['source'] === 'communication',
            'documents' => $event['source'] === 'document',
            'administration' => in_array($event['source'], ['system', 'approval'], true),
            'exceptions' => in_array($event['severity'], ['warning', 'danger'], true),
            default => true,
        };
    }

    private function withTraceIntegrity(array $event): array
    {
        $deduplicationKey = $event['correlation_id']
            ? $event['source'] . ':' . $event['correlation_id']
            : $event['id'];
        $hasPersistedActorId = !empty($event['actor']['id']);
        $hasActorSnapshot = !empty($event['actor_display_snapshot']);

        return [
            ...$event,
            'deduplication_key' => $deduplicationKey,
            'attribution' => [
                'actor_id' => $hasPersistedActorId ? 'persisted_foreign_key' : 'system_or_unrecorded',
                'actor_display' => $hasActorSnapshot ? 'event_time_snapshot'
                    : ($hasPersistedActorId ? 'current_directory_display' : 'system_label'),
                'actor_display_immutable' => $hasActorSnapshot || !$hasPersistedActorId,
                'timestamp' => 'persisted_source_timestamp',
                'source' => 'canonical_persisted_record',
            ],
        ];
    }

    private function encodeCursor(string $timestamp, string $id): string
    {
        return rtrim(strtr(base64_encode($timestamp . '|' . $id), '+/', '-_'), '=');
    }

    private function decodeCursor(string $cursor): ?array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!$decoded || !str_contains($decoded, '|'))
            return null;
        return explode('|', $decoded, 2);
    }

    private function isBeforeCursor(array $event, array $cursor): bool
    {
        return $event['occurred_at'] < $cursor[0]
            || ($event['occurred_at'] === $cursor[0] && $event['id'] < $cursor[1]);
    }

}
