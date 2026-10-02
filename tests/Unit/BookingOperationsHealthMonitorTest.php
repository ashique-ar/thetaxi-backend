<?php

use App\Services\BookingOperationsHealthMonitor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;


beforeEach(function () {
    Cache::flush();
    config()->set('booking_observability.health_alert_throttle_seconds', 900);
});

it('emits one bounded stale-tracking signal without coordinates', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with('booking_tracking_freshness_unhealthy', Mockery::on(fn (array $context) =>
            $context['booking_id'] === 'booking-1'
            && $context['booking_item_id'] === 'item-1'
            && $context['freshness'] === 'stale'
            && !array_key_exists('active_position', $context)
            && !array_key_exists('latitude', $context)
        ));

    $summary = [
        'booking_id' => 'booking-1',
        'booking_item_id' => 'item-1',
        'assignment_id' => 'assignment-1',
        'driver_id' => 'driver-1',
        'enabled' => true,
        'freshness' => 'stale',
        'last_reported_at' => now()->subMinutes(10)->toIso8601String(),
        'active_position' => ['latitude' => 6.9, 'longitude' => 79.8],
    ];

    $monitor = app(BookingOperationsHealthMonitor::class);
    $monitor->recordTrackingFreshness($summary);
    $monitor->recordTrackingFreshness($summary);
});

it('records failed uploads using identifiers and exception class only', function () {
    Log::shouldReceive('error')
        ->once()
        ->with('booking_location_upload_failed', Mockery::on(fn (array $context) =>
            $context['booking_item_id'] === 'item-1'
            && $context['upload_type'] === 'buffered'
            && $context['exception_class'] === RuntimeException::class
            && !array_key_exists('coordinates', $context)
            && !array_key_exists('error', $context)
        ));

    app(BookingOperationsHealthMonitor::class)->recordLocationUploadFailure([
        'driver_id' => 'driver-1',
        'session_id' => 'session-1',
        'assignment_id' => 'assignment-1',
        'booking_id' => 'booking-1',
        'booking_item_id' => 'item-1',
        'coordinates' => [6.9, 79.8],
    ], 'buffered', new RuntimeException('database detail must not be logged'));
});

it('records lifecycle inconsistency from canonical status projections only', function () {
    Log::shouldReceive('error')
        ->once()
        ->with('booking_lifecycle_state_mismatch', [
            'booking_id' => 'booking-1',
            'booking_item_id' => 'item-1',
            'summary_status' => 'ongoing_active',
            'contract_status' => 'return_pending',
        ]);

    app(BookingOperationsHealthMonitor::class)->recordLifecycleMismatch([
        'booking_id' => 'booking-1',
        'booking_item_id' => 'item-1',
        'summary_status' => 'ongoing_active',
        'contract_status' => 'return_pending',
        'blocking_reasons' => ['must not be logged'],
    ]);
});

it('records settlement reconciliation issue codes without monetary values', function () {
    Log::shouldReceive('error')
        ->once()
        ->with('booking_settlement_reconciliation_mismatch', [
            'settlement_id' => 'settlement-1',
            'issue_codes' => ['aggregate_outstanding_total', 'item_outstanding_total'],
        ]);

    app(BookingOperationsHealthMonitor::class)->recordSettlementMismatch('settlement-1', [
        'aggregate_outstanding_total',
        'item_outstanding_total',
        'aggregate_outstanding_total',
    ]);
});
