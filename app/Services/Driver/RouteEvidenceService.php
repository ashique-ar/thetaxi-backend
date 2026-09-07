<?php

namespace App\Services\Driver;

use App\Support\DriverRouteEvidenceContract;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class RouteEvidenceService
{
    // Normal mobile sampling is one second. A gap longer than 30 seconds is
    // discontinuous evidence and must never be bridged as travelled movement.
    public const GAP_THRESHOLD_SECONDS = 30;
    public const MAX_ACCURACY_METERS = 100;
    public const MAX_PLAUSIBLE_SPEED_KPH = 180;

    public function calculate(Collection $points, ?Carbon $tripStartedAt, ?Carbon $tripCompletedAt): array
    {
        $ordered = $points->sortBy([
            ['recorded_at', 'asc'],
            ['id', 'asc'],
        ])->values();
        $recordedCount = $ordered->count();
        $accepted = collect();
        $rejectedCount = 0;
        $outsideTripWindowCount = 0;
        $qualityRejectedCount = 0;

        foreach ($ordered as $point) {
            $recordedAt = $point->recorded_at ? Carbon::parse($point->recorded_at)->utc() : null;
            $latitude = (float) $point->latitude;
            $longitude = (float) $point->longitude;
            $inWindow = $recordedAt && $tripStartedAt
                && $recordedAt->gte($tripStartedAt)
                && (!$tripCompletedAt || $recordedAt->lte($tripCompletedAt));
            $valid = $latitude >= -90 && $latitude <= 90
                && $longitude >= -180 && $longitude <= 180
                && ($point->accuracy === null || (float) $point->accuracy <= self::MAX_ACCURACY_METERS);

            if (!$valid) {
                $rejectedCount++;
                $qualityRejectedCount++;
                continue;
            }

            if (!$inWindow) {
                $outsideTripWindowCount++;
                continue;
            }

            $accepted->push($point);
        }

        $distance = 0.0;
        $gapCount = 0;
        $longestGap = 0;
        $segmentCount = 0;
        $movementRejectedCount = 0;
        $previous = null;

        foreach ($accepted as $point) {
            if (!$previous) {
                $previous = $point;
                continue;
            }
            $seconds = max(0, Carbon::parse($previous->recorded_at)->diffInSeconds(Carbon::parse($point->recorded_at), false));
            if ($seconds === 0) {
                $movementRejectedCount++;
                $rejectedCount++;
                $qualityRejectedCount++;
                continue;
            }
            $longestGap = max($longestGap, $seconds);
            if ($seconds > self::GAP_THRESHOLD_SECONDS) {
                $gapCount++;
                $previous = $point;
                continue;
            }
            $segmentDistance = $this->haversineKm($previous, $point);
            $speed = $seconds > 0 ? ($segmentDistance / $seconds) * 3600 : 0;
            if ($speed > self::MAX_PLAUSIBLE_SPEED_KPH) {
                $movementRejectedCount++;
                $rejectedCount++;
                $qualityRejectedCount++;
                continue;
            }
            $distance += $segmentDistance;
            $segmentCount++;
            $previous = $point;
        }

        if ($accepted->isNotEmpty() && $tripCompletedAt) {
            $endingGap = Carbon::parse($accepted->last()->recorded_at)->diffInSeconds($tripCompletedAt, false);
            if ($endingGap > self::GAP_THRESHOLD_SECONDS) {
                $gapCount++;
                $longestGap = max($longestGap, $endingGap);
            }
        }

        // Assignment tracking normally starts before the passenger trip. Those
        // points remain route evidence but do not make in-trip GPS invalid.
        $trustworthy = $segmentCount > 0 && $gapCount === 0 && $rejectedCount === 0;
        $coverage = $recordedCount === 0 ? 'not_recorded'
            : ($segmentCount === 0 ? 'insufficient' : ($trustworthy ? 'healthy' : 'partial'));

        return [
            'classification' => $segmentCount > 0 ? 'recorded_validated' : 'unavailable',
            'source' => 'driver_route_points',
            'confidence' => $trustworthy ? 'high' : ($segmentCount > 0 ? 'medium' : 'none'),
            'recorded_distance_km' => $segmentCount > 0 ? round($distance, 3) : null,
            'coverage_status' => $coverage,
            'distance_trustworthy' => $trustworthy,
            'recorded_point_count' => $recordedCount,
            'valid_tracking_point_count' => $recordedCount - $rejectedCount,
            'accepted_point_count' => $accepted->count() - $movementRejectedCount,
            'rejected_point_count' => $rejectedCount,
            'outside_trip_window_point_count' => $outsideTripWindowCount,
            // Temporary explicit alias for portal versions deployed during the
            // count split. It is identical to the canonical rejected count.
            'quality_rejected_point_count' => $rejectedCount,
            'gap_count' => $gapCount,
            'longest_gap_seconds' => $longestGap,
            'first_valid_point_at' => $accepted->first()?->recorded_at?->utc()->toIso8601String(),
            'last_valid_point_at' => $accepted->last()?->recorded_at?->utc()->toIso8601String(),
            'calculation_version' => DriverRouteEvidenceContract::CALCULATION_VERSION,
            'warnings' => array_values(array_filter([
                $outsideTripWindowCount > 0 ? 'Valid assignment tracking outside the passenger-carrying phase is retained and shown, but excluded from passenger-trip mileage.' : null,
                $qualityRejectedCount > 0 ? 'Some route points failed GPS quality validation.' : null,
                $gapCount > 0 ? 'Recorded GPS contains one or more coverage gaps.' : null,
            ])),
            'pricing_effect' => DriverRouteEvidenceContract::PRICING_EFFECT,
        ];
    }

    private function haversineKm(object $from, object $to): float
    {
        $earth = 6371.0088;
        $lat1 = deg2rad((float) $from->latitude);
        $lat2 = deg2rad((float) $to->latitude);
        $latDelta = $lat2 - $lat1;
        $lonDelta = deg2rad((float) $to->longitude - (float) $from->longitude);
        $a = sin($latDelta / 2) ** 2 + cos($lat1) * cos($lat2) * sin($lonDelta / 2) ** 2;
        return $earth * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
    }
}
