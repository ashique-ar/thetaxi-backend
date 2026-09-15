<?php

uses(Tests\TestCase::class);

it('exposes item-scoped trace and tracking summary behind booking view permission', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)
        ->toContain("Route::get('trace'")
        ->toContain("Route::get('tracking-summary'")
        ->toContain("->middleware('permission:bookings.view')");
});

it('protects raw replay coordinates with a dedicated permission', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $seeder = file_get_contents(database_path('seeders/AllPermissionsSeeder.php'));

    expect($routes)
        ->toContain("Route::get('route-replay'")
        ->toContain("->middleware('permission:bookings.tracking_replay')")
        ->and($seeder)->toContain("'bookings.tracking_replay'");
});

it('validates booking item ownership before returning observability data', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/BookingObservabilityController.php'));

    expect($controller)
        ->toContain("'booking_item_id' => \$rules")
        ->toContain('!$booking->bookingItems()->whereKey($itemId)->exists()')
        ->toContain("abort(422, 'Selected booking item does not belong to this booking')");
});

it('keeps operational route evidence read only and separate from pricing', function () {
    $service = file_get_contents(app_path('Services/BookingObservabilityService.php'));

    expect($service)
        ->toContain('RoutePoint::query()')
        ->toContain("'next_cursor'")
        ->toContain("'truncated'")
        ->not->toContain('PricingService')
        ->not->toContain('calculatePricing')
        ->not->toContain('pricing_snapshot')
        ->not->toContain('pricing_breakdown')
        ->not->toContain('->update(')
        ->not->toContain('->save(');
});

it('uses deterministic ordering for trace and route replay', function () {
    $service = file_get_contents(app_path('Services/BookingObservabilityService.php'));

    expect($service)
        ->toContain("->orderByDesc('timestamp')->orderByDesc('id')")
        ->toContain("->orderBy('recorded_at')->orderBy('id')")
        ->toContain("->latest('recorded_at')");
});

it('exposes booking-scoped communication and document feeds without raw storage paths', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/BookingObservabilityController.php'));

    expect($routes)
        ->toContain("Route::get('communications'")
        ->toContain("Route::get('documents'");
    expect($controller)
        ->toContain('$this->observability->communications($booking, $itemId)')
        ->toContain('$this->observability->documents($booking, $itemId)');
});

it('owns the active trips projection under booking view permission without exposing route history', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $service = file_get_contents(app_path('Services/BookingObservabilityService.php'));

    expect($routes)
        ->toContain("Route::get('bookings/active-trips'")
        ->toContain("->middleware('permission:bookings.view')");
    expect($service)
        ->toContain('ROW_NUMBER() OVER (PARTITION BY assignment_id')
        ->toContain("'booking_item_id'")
        ->toContain("'freshness'")
        ->toContain("'position'")
        ->not->toContain("'points' => \$items");
});

it('prefers the active replacement assignment and narrowly correlates session-gap points', function () {
    $service = file_get_contents(app_path('Services/BookingObservabilityService.php'));

    expect($service)
        ->toContain("CASE WHEN status = 'active'")
        ->toContain("trip_phase NOT IN ('completed', 'declined')")
        ->toContain("withCount('routePoints')")
        ->toContain("orderByDesc('route_points_count')")
        ->toContain("where('assignment_id', \$assignment->id)")
        ->toContain("whereNull('assignment_id')")
        ->toContain("whereIn('session_id', \$sessionIds)")
        ->toContain("whereBetween('recorded_at', [\$windowStart, \$windowEnd])");
});

it('keeps corporate progress item scoped and fails closed for public tracking links', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Corporate/CorporateBookingController.php'));

    expect($controller)
        ->toContain("'booking_item_id' => ['nullable', 'uuid']")
        ->toContain('Select a trip before viewing live progress.')
        ->toContain('$this->observability->trackingSummary')
        ->toContain("'raw_tracking' => false")
        ->toContain("'customer_tracking_link' => 'unavailable_policy_not_configured'")
        ->not->toContain('temporarySignedRoute');
});

it('paginates persisted booking and selected-item audit events in stable database order', function () {
    $service = file_get_contents(app_path('Services/BookingObservabilityService.php'));

    expect($service)
        ->toContain('AuditLog::query()')
        ->toContain("where('entity', 'Booking')")
        ->toContain("where('entity', 'BookingItem')")
        ->toContain("whereNull('details->booking_item_id')")
        ->toContain("orWhere('details->booking_item_id', \$bookingItemId)")
        ->toContain("orderByDesc('timestamp')->orderByDesc('id')")
        ->toContain("where('timestamp', '<=', \$decodedCursor[0])")
        ->not->toContain("\$summary['timeline']")
        ->not->toContain('mapSyntheticTimelineEvent');
});

it('merges booking financial audits and selected-item persisted trip milestones into the trace', function () {
    $service = file_get_contents(app_path('Services/BookingObservabilityService.php'));
    $financialEvent = file_get_contents(app_path('Models/Finance/FinancialAuditEvent.php'));

    expect($service)
        ->toContain('FinancialAuditEvent::query()')
        ->toContain("->where('booking_id', \$booking->id)")
        ->toContain("'booking_item_id' => null")
        ->toContain("->where('booking_item_id', \$bookingItemId)")
        ->toContain("'pickup_arrived' => [\$assignment->pickup_arrived_at")
        ->toContain("'trip_started' => [\$assignment->trip_started_at")
        ->toContain("'trip_completed' => [\$assignment->trip_completed_at")
        ->toContain("'source' => 'trip'")
        ->toContain("'source' => 'finance'")
        ->not->toContain('total_distance_km');
    expect($financialEvent)->toContain('function performedBy(): BelongsTo');
});

it('shares sanitized booking-linked communication and document projections with the canonical trace', function () {
    $service = file_get_contents(app_path('Services/BookingObservabilityService.php'));

    expect($service)
        ->toContain('private function communicationItems(')
        ->toContain('private function documentItems(')
        ->toContain("'notification_logs' => 'unavailable_no_booking_link'")
        ->toContain("->where('booking_id', \$booking->id)")
        ->toContain("->where('booking_item_id', \$bookingItemId)")
        ->toContain("->when(!\$bookingItemId, fn (Collection \$items) => \$items->whereNull('booking_item_id'))")
        ->toContain("'source' => 'communication'")
        ->toContain("'source' => 'document'")
        ->toContain("'download_url' => null")
        ->not->toContain("'pdf_path' =>")
        ->not->toContain("'pdf_disk' =>");
});

it('applies allowlisted trace filters before cursor pagination and exposes only workspace evidence sections', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/BookingObservabilityController.php'));
    $service = file_get_contents(app_path('Services/BookingObservabilityService.php'));

    expect($controller)
        ->toContain('in:all,operations,journey,finance,communications,documents,administration,exceptions')
        ->toContain("\$validated['filter'] ?? 'all'");
    expect($service)
        ->toContain('->filter(fn (array $event): bool => $this->matchesTraceFilter($event, $filter))')
        ->toContain("'selected_filter' => \$filter")
        ->toContain("['section' => 'payments', 'label' => 'View payments']")
        ->toContain("['section' => 'tracking', 'label' => 'View trip tracking']")
        ->toContain("['section' => 'communication', 'label' => 'View communication']")
        ->toContain("['section' => 'documents', 'label' => 'View documents']")
        ->not->toContain("'download_url' => '/");
});

it('segments replay chronologically at lifecycle and telemetry gaps with page-scoped quality evidence', function () {
    $service = file_get_contents(app_path('Services/BookingObservabilityService.php'));

    expect($service)
        ->toContain('private function segmentRoutePoints(')
        ->toContain("'gap_threshold_seconds' => RouteEvidenceService::GAP_THRESHOLD_SECONDS")
        ->toContain("'implausible_movement'")
        ->toContain("'invalid_coordinate_count'")
        ->toContain("'inaccurate_point_count'")
        ->toContain("'implausible_speed_count'")
        ->toContain("\$current['phase'] !== \$phase")
        ->toContain("\$gapSeconds > \$quality['gap_threshold_seconds']")
        ->toContain("return 'post_dropoff'")
        ->toContain("'pricing_effect' => 'none'")
        ->not->toContain('pricing_breakdown');
});

it('replays every selected-item assignment and carries the predecessor across cursor pages', function () {
    $service = file_get_contents(app_path('Services/BookingObservabilityService.php'));

    expect($service)
        ->toContain("->where('booking_item_id', \$bookingItemId)")
        ->toContain('private function routeQueryForAssignments(Collection $assignments)')
        ->toContain("whereIn('assignment_id', \$assignments->pluck('id'))")
        ->toContain("whereNull('assignment_id')->whereIn('session_id', \$sessionIds)")
        ->toContain("'boundary' => \$assignmentChanged ? 'replacement_assignment' : null")
        ->toContain("'continues_previous_segment'")
        ->toContain("'assignment_count' => \$assignments->count()")
        ->toContain("orderByDesc('recorded_at')->orderByDesc('id')->first()")
        ->toContain("'assignment_id' => \$assignmentId");
});

it('audits replay access and fails route export closed behind policy and a separate permission', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/BookingObservabilityController.php'));
    $config = file_get_contents(config_path('booking_observability.php'));

    expect($routes)
        ->toContain("Route::get('route-replay/export'")
        ->toContain("permission:bookings.tracking_export");
    expect($controller)
        ->toContain("'booking_route_replay_viewed'")
        ->toContain("'booking_route_replay_exported'")
        ->toContain('Cache::add($cacheKey')
        ->toContain("abort_unless(config('booking_observability.route_retention_days'), 409")
        ->toContain("abort_if(\$data['truncated'], 422")
        ->toContain("'booking_item_id' => \$itemId")
        ->toContain("'Content-Type' => 'text/csv; charset=UTF-8'");
    expect($config)
        ->toContain("env('BOOKING_ROUTE_RETENTION_DAYS')")
        ->toContain("env('BOOKING_ROUTE_EXPORT_MAX_POINTS', 10000)");
});

it('normalizes persisted attribution and deterministic duplicate protection across trace sources', function () {
    $service = file_get_contents(app_path('Services/BookingObservabilityService.php'));

    expect($service)
        ->toContain('->map(fn (array $event): array => $this->withTraceIntegrity($event))')
        ->toContain("->unique('deduplication_key')")
        ->toContain("'persisted_foreign_key'")
        ->toContain("'current_directory_display'")
        ->toContain("'actor_display_immutable' => \$hasActorSnapshot || !\$hasPersistedActorId")
        ->toContain("'persisted_source_timestamp'")
        ->toContain("'canonical_persisted_record'")
        ->toContain("['assignment', \$assignment->id, \$type]")
        ->toContain("['finance', \$event->subject_type, \$event->subject_id, \$event->event_type]")
        ->toContain("\$isConfirmationEvent ? \$assignment->confirmed_by : \$assignment->driver_id");
});

it('captures event-time actor display snapshots without backfilling legacy trace history', function () {
    $audit = file_get_contents(app_path('Models/AuditLog.php'));
    $financial = file_get_contents(app_path('Models/Finance/FinancialAuditEvent.php'));
    $assignment = file_get_contents(app_path('Models/DriverAssignment.php'));
    $service = file_get_contents(app_path('Services/BookingObservabilityService.php'));
    $migration = file_get_contents(database_path('migrations/2026_07_22_000005_add_trace_actor_snapshots_to_driver_assignments.php'));

    expect($audit)->toContain("'actor_display_snapshot'")->toContain('static::creating(');
    expect($financial)->toContain("'actor_display_snapshot'")->toContain('static::creating(');
    expect($assignment)
        ->toContain("'assigned_by_name_snapshot'")
        ->toContain("'confirmed_by_name_snapshot'")
        ->toContain("'driver_name_snapshot'")
        ->toContain("isDirty('confirmed_by')");
    expect($service)
        ->toContain("'event_time_snapshot'")
        ->toContain("'actor_display_immutable' => \$hasActorSnapshot || !\$hasPersistedActorId");
    expect($migration)->not->toContain('DB::table')->not->toContain('update(');
});
