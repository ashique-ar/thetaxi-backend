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
}
