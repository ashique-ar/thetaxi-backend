<?php

namespace App\Services\Driver;

use App\Models\DriverAssignment;
use App\Models\WaitingTimeRecord;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Waiting Time Service
 *
 * Detects stationary periods during in-progress trips by analyzing
 * consecutive route points. Creates/closes WaitingTimeRecords automatically.
 *
 * Stationary = speed < 3 km/h for >= 2 continuous minutes.
 *
 * @see Requirements 7.1–7.5
 */
class WaitingTimeService
{
    private const SPEED_THRESHOLD_KMH = 3.0;
    private const MIN_STATIONARY_SECONDS = 120; // 2 minutes
    private const MIN_VERIFIED_STATIONARY_SECONDS = 90;
    private const MAX_CONTIGUOUS_GAP_SECONDS = 30;

    /**
     * Analyze recent route points for an assignment to detect/close waiting periods.
     */
    public function analyzeRoutePoints(DriverAssignment $assignment): void
    {
        $recentPoints = $assignment->routePoints()
            ->orderBy('recorded_at', 'desc')
            ->limit(30)
            ->get()
            ->sortBy('recorded_at')
            ->values();

        if ($recentPoints->count() < 2) {
            return;
        }

        $latestPoint = $recentPoints->last();
        $isStationary = ($latestPoint->speed ?? 0) < self::SPEED_THRESHOLD_KMH;

        $openRecord = WaitingTimeRecord::where('assignment_id', $assignment->id)
            ->whereNull('end_time')
            ->first();

        if ($isStationary) {
            $stationaryPeriod = $this->detectStationaryPeriod($recentPoints);

            if ($stationaryPeriod && !$openRecord) {
                // Create new waiting record
                WaitingTimeRecord::create([
                    'assignment_id' => $assignment->id,
                    'start_time' => $stationaryPeriod['start_time'],
                    'start_latitude' => $stationaryPeriod['start_latitude'],
                    'start_longitude' => $stationaryPeriod['start_longitude'],
                ]);
            }
        } elseif ($openRecord) {
            // Vehicle resumed movement — close the record
            $openRecord->update([
                'end_time' => $latestPoint->recorded_at,
                'end_latitude' => $latestPoint->latitude,
                'end_longitude' => $latestPoint->longitude,
                'duration_seconds' => (int) Carbon::parse($openRecord->start_time)
                    ->diffInSeconds($latestPoint->recorded_at),
            ]);
        }
    }

    /**
     * Detect a stationary period from consecutive route points.
     *
     * Returns start info if consecutive points with speed < 3 km/h
     * span >= 2 minutes, or null otherwise.
     */
    public function detectStationaryPeriod(Collection $recentPoints): ?array
    {
        if ($recentPoints->count() < 2) {
            return null;
        }

        $stationaryStart = null;
        $stationaryStartPoint = null;

        foreach ($recentPoints as $point) {
            $speed = $point->speed ?? 0;

            if ($speed < self::SPEED_THRESHOLD_KMH) {
                if ($stationaryStart === null) {
                    $stationaryStart = $point->recorded_at;
                    $stationaryStartPoint = $point;
                }

                $elapsed = Carbon::parse($stationaryStart)->diffInSeconds($point->recorded_at);

                if ($elapsed >= self::MIN_STATIONARY_SECONDS) {
                    return [
                        'start_time' => $stationaryStart,
                        'start_latitude' => $stationaryStartPoint->latitude,
                        'start_longitude' => $stationaryStartPoint->longitude,
                    ];
                }
            } else {
                // Movement detected — reset
                $stationaryStart = null;
                $stationaryStartPoint = null;
            }
        }

        return null;
    }

    /**
     * Close all open waiting records for an assignment.
     */
    public function closeOpenWaitingRecords(DriverAssignment $assignment): void
    {
        $now = Carbon::now();

        $openRecords = WaitingTimeRecord::where('assignment_id', $assignment->id)
            ->whereNull('end_time')
            ->get();

        foreach ($openRecords as $record) {
            $lastPoint = $assignment->routePoints()
                ->orderBy('recorded_at', 'desc')
                ->first();

            $record->update([
                'end_time' => $now,
                'end_latitude' => $lastPoint?->latitude,
                'end_longitude' => $lastPoint?->longitude,
                'duration_seconds' => (int) Carbon::parse($record->start_time)->diffInSeconds($now),
            ]);
        }
    }

    /**
     * Get total waiting time and period count for an assignment.
     */
    public function getTotalWaitingTime(DriverAssignment $assignment): array
    {
        $records = WaitingTimeRecord::where('assignment_id', $assignment->id)->get();

        $totalSeconds = 0;

        foreach ($records as $record) {
            if ($record->duration_seconds !== null) {
                $totalSeconds += $record->duration_seconds;
            } elseif ($record->start_time && $record->end_time === null) {
                // Still open — calculate live duration
                $totalSeconds += (int) Carbon::parse($record->start_time)->diffInSeconds(Carbon::now());
            }
        }

        return [
            'total_waiting_time_seconds' => $totalSeconds,
            'waiting_period_count' => $records->count(),
        ];
    }

    /** Return only stationary records occurring after passenger pickup. */
    public function getHireWaitingTime(DriverAssignment $assignment): array
    {
        if (!$assignment->trip_started_at) {
            return ['total_waiting_time_seconds' => 0, 'waiting_period_count' => 0];
        }

        $records = WaitingTimeRecord::where('assignment_id', $assignment->id)
            ->where(function ($query) use ($assignment): void {
                $query->where('end_time', '>', $assignment->trip_started_at)
                    ->orWhereNull('end_time');
            })
            ->get();
        $totalSeconds = 0;
        foreach ($records as $record) {
            $start = Carbon::parse($record->start_time)->max($assignment->trip_started_at);
            $end = $record->end_time ? Carbon::parse($record->end_time) : Carbon::now('UTC');
            if ($assignment->trip_completed_at) {
                $end = $end->min($assignment->trip_completed_at);
            }
            if ($end->gt($start)) {
                $totalSeconds += (int) $start->diffInSeconds($end);
            }
        }

        return [
            'total_waiting_time_seconds' => $totalSeconds,
            'waiting_period_count' => $records->count(),
        ];
    }

    /**
     * Reconstruct meter waiting from immutable in-trip route evidence.
     * Missing device speed is derived from consecutive coordinates. Gaps are
     * boundaries and are never counted as waiting time.
     */
    public function calculateValidatedTripWaitingTime(DriverAssignment $assignment): array
    {
        if (!$assignment->trip_started_at) {
            return ['total_waiting_time_seconds' => 0, 'waiting_period_count' => 0];
        }

        $points = $assignment->routePoints()
            ->where('recorded_at', '>=', $assignment->trip_started_at)
            ->when($assignment->trip_completed_at, fn ($query) => $query->where('recorded_at', '<=', $assignment->trip_completed_at))
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        $waitingSeconds = 0;
        $waitingPeriods = 0;
        $stationarySeconds = 0;
        $previous = null;

        foreach ($points as $point) {
            if (!$previous) {
                $previous = $point;
                continue;
            }

            $seconds = (int) Carbon::parse($previous->recorded_at)
                ->diffInSeconds(Carbon::parse($point->recorded_at), false);
            if ($seconds <= 0 || $seconds > self::MAX_CONTIGUOUS_GAP_SECONDS) {
                [$waitingSeconds, $waitingPeriods] = $this->finishStationaryPeriod(
                    $stationarySeconds,
                    $waitingSeconds,
                    $waitingPeriods
                );
                $stationarySeconds = 0;
                $previous = $point;
                continue;
            }

            $speedKmh = $point->speed !== null
                ? max(0.0, (float) $point->speed * 3.6)
                : ($this->distanceKm($previous, $point) / $seconds) * 3600;

            if ($speedKmh < self::SPEED_THRESHOLD_KMH) {
                $stationarySeconds += $seconds;
            } else {
                [$waitingSeconds, $waitingPeriods] = $this->finishStationaryPeriod(
                    $stationarySeconds,
                    $waitingSeconds,
                    $waitingPeriods
                );
                $stationarySeconds = 0;
            }
            $previous = $point;
        }

        [$waitingSeconds, $waitingPeriods] = $this->finishStationaryPeriod(
            $stationarySeconds,
            $waitingSeconds,
            $waitingPeriods
        );

        return [
            'total_waiting_time_seconds' => $waitingSeconds,
            'waiting_period_count' => $waitingPeriods,
            'source' => 'validated_route_points',
        ];
    }

    private function finishStationaryPeriod(int $stationarySeconds, int $total, int $periods): array
    {
        if ($stationarySeconds <= self::MIN_VERIFIED_STATIONARY_SECONDS) {
            return [$total, $periods];
        }

        return [
            // The grace period only validates that this was a real stop. Keep the
            // complete stationary interval because the pricing definition applies
            // its own free-waiting allowance (for example, the first 10 minutes).
            $total + $stationarySeconds,
            $periods + 1,
        ];
    }

    private function distanceKm(object $from, object $to): float
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
