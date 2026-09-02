<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class BookingOperationsHealthMonitor
{
    public function recordTrackingFreshness(array $summary): void
    {
        $freshness = (string) ($summary['freshness'] ?? 'unknown');
        if (!($summary['enabled'] ?? false) || !in_array($freshness, ['delayed', 'stale', 'never_reported'], true)) {
            return;
        }

        $context = array_filter([
            'booking_id' => $summary['booking_id'] ?? null,
            'booking_item_id' => $summary['booking_item_id'] ?? null,
            'assignment_id' => $summary['assignment_id'] ?? null,
            'driver_id' => $summary['driver_id'] ?? null,
            'trip_phase' => $summary['trip_phase'] ?? null,
            'freshness' => $freshness,
            'last_reported_age_seconds' => $this->ageSeconds($summary['last_reported_at'] ?? null),
        ], fn ($value) => $value !== null && $value !== '');

        if ($this->acquire('tracking-' . $freshness, $context)) {
            Log::warning('booking_tracking_freshness_unhealthy', $context);
        }
    }

    public function recordLocationUploadFailure(array $context, string $uploadType, Throwable $exception): void
    {
        $safeContext = array_filter([
            'driver_id' => $context['driver_id'] ?? null,
            'session_id' => $context['session_id'] ?? null,
            'assignment_id' => $context['assignment_id'] ?? null,
            'booking_id' => $context['booking_id'] ?? null,
            'booking_item_id' => $context['booking_item_id'] ?? null,
            'upload_type' => $uploadType,
            'exception_class' => $exception::class,
        ], fn ($value) => $value !== null && $value !== '');

        if ($this->acquire('upload-failed', $safeContext)) {
            Log::error('booking_location_upload_failed', $safeContext);
        }
    }

    public function recordLifecycleMismatch(array $context): void
    {
        $safeContext = array_filter([
            'booking_id' => $context['booking_id'] ?? null,
            'booking_item_id' => $context['booking_item_id'] ?? null,
            'summary_status' => $context['summary_status'] ?? null,
            'contract_status' => $context['contract_status'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        if ($this->acquire('lifecycle-mismatch', $safeContext)) {
            Log::error('booking_lifecycle_state_mismatch', $safeContext);
        }
    }

    public function recordDriverTrackingHealth(array $context, array $health): void
    {
        $state = (string) ($health['state'] ?? 'unknown');
        $safeContext = array_filter([
            'driver_id' => $context['driver_id'] ?? null,
            'session_id' => $context['session_id'] ?? null,
            'assignment_id' => $context['assignment_id'] ?? null,
            'booking_id' => $context['booking_id'] ?? null,
            'booking_item_id' => $context['booking_item_id'] ?? null,
            'state' => $state,
            'queue_count' => isset($health['queue_count']) ? (int) $health['queue_count'] : null,
            'oldest_queue_age_seconds' => isset($health['oldest_queue_age_seconds']) ? (int) $health['oldest_queue_age_seconds'] : null,
            'last_fix_age_seconds' => isset($health['last_fix_age_seconds']) ? (int) $health['last_fix_age_seconds'] : null,
            'app_version' => $health['app_version'] ?? null,
            'app_build' => $health['app_build'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        if (!empty($safeContext['assignment_id'])) {
            $key = $this->driverTrackingHealthKey((string) $safeContext['assignment_id']);
            $previous = Cache::get($key, []);
            $current = array_merge($safeContext, [
                'reported_at' => now()->toIso8601String(),
            ]);
            if ($state !== 'healthy' && $state !== 'recovered') {
                $current['last_issue'] = [
                    'state' => $state,
                    'reported_at' => $current['reported_at'],
                ];
            } elseif (is_array($previous) && isset($previous['last_issue'])) {
                $current['last_issue'] = $previous['last_issue'];
            }
            Cache::put($key, $current, now()->addHours(6));
        }

        if (!in_array($state, ['healthy', 'recovered'], true) && $this->acquire('driver-tracking-' . $state, $safeContext)) {
            $level = in_array($state, ['blocked', 'severe_gap'], true) ? 'error' : 'warning';
            Log::$level('booking_driver_tracking_health', $safeContext);
        }
    }

    public function latestDriverTrackingHealth(?string $assignmentId): ?array
    {
        if (!$assignmentId) {
            return null;
        }

        $health = Cache::get($this->driverTrackingHealthKey($assignmentId));
        return is_array($health) ? $health : null;
    }

    public function recordSettlementMismatch(string $settlementId, array $issueCodes): void
    {
        $safeContext = [
            'settlement_id' => $settlementId,
            'issue_codes' => array_values(array_unique(array_map('strval', $issueCodes))),
        ];

        if ($safeContext['issue_codes'] !== [] && $this->acquire('settlement-mismatch', $safeContext)) {
            Log::error('booking_settlement_reconciliation_mismatch', $safeContext);
        }
    }

    private function acquire(string $signal, array $context): bool
    {
        $seconds = max(1, (int) config('booking_observability.health_alert_throttle_seconds', 900));
        $key = 'booking-health:' . $signal . ':' . sha1(json_encode($context, JSON_THROW_ON_ERROR));
        return Cache::add($key, true, now()->addSeconds($seconds));
    }

    private function ageSeconds(?string $timestamp): ?int
    {
        return $timestamp ? (int) Carbon::parse($timestamp)->diffInSeconds(now(), true) : null;
    }

    private function driverTrackingHealthKey(string $assignmentId): string
    {
        return 'booking-driver-tracking-health:' . $assignmentId;
    }
}
