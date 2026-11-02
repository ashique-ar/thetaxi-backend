<?php

namespace App\Services\Tours;

use App\Services\GoogleMapsService;
use InvalidArgumentException;
use RuntimeException;

class TourRoutePlanner
{
    public function __construct(private readonly GoogleMapsService $maps) {}

    public function suggest(array $stops, string $startTime = '09:00'): array
    {
        $stops = array_values($stops);
        $this->validateStops($stops, $startTime);
        $cache = [];

        try {
            $original = $this->measure($stops, $cache, $startTime);
            $suggested = $original;
            // shortcut: route search caps at 256 unique directed legs per suggestion; raise only with measured provider budgets.
            for ($pass = 0; $pass < count($stops) && count($cache) < 256; $pass++) {
                $improved = false;
                $currentStops = $suggested['stops'];
                for ($start = 1; $start < count($currentStops) - 2 && count($cache) < 256; $start++) {
                    for ($end = $start + 1; $end < count($currentStops) - 1 && count($cache) < 256; $end++) {
                        $segment = array_slice($currentStops, $start, $end - $start + 1);
                        if (collect($segment)->contains(fn(array $stop): bool => ($stop['locked'] ?? false) || isset($stop['arrival_time']))) {
                            continue;
                        }
                        $candidateStops = [
                            ...array_slice($currentStops, 0, $start),
                            ...array_reverse($segment),
                            ...array_slice($currentStops, $end + 1),
                        ];
                        $candidate = $this->measure($candidateStops, $cache, $startTime);
                        $fewerConflicts = count($candidate['timing_issues']) < count($suggested['timing_issues']);
                        $shorterFeasibleRoute = $candidate['timing_issues'] === []
                            && $suggested['timing_issues'] === []
                            && $candidate['distance_km'] < $suggested['distance_km']
                            && $candidate['duration_seconds'] <= $suggested['duration_seconds'];
                        if ($fewerConflicts || $shorterFeasibleRoute) {
                            $suggested = $candidate;
                            $improved = true;
                            break 2;
                        }
                    }
                }
                if (! $improved) break;
            }

            return [
                'status' => $suggested['timing_issues'] === [] ? 'ready' : 'review_required',
                'original' => $original,
                'suggested' => $suggested,
                'changed' => array_column($suggested['stops'], 'id') !== array_column($stops, 'id'),
                'issues' => $suggested['timing_issues'],
            ];
        } catch (\Exception) {
            $unavailable = [
                'stops' => $stops,
                'legs' => [],
                'distance_km' => null,
                'duration_seconds' => null,
                'visit_duration_seconds' => (int) round(array_sum(array_column($stops, 'visit_minutes')) * 60),
                'total_duration_seconds' => null,
            ];

            return [
                'status' => 'unavailable',
                'original' => $unavailable,
                'suggested' => $unavailable,
                'changed' => false,
                'issues' => [['code' => 'route_unavailable', 'message' => 'The required route could not be verified. Keep the selected visits and retry or request staff assistance.']],
            ];
        }
    }

    private function validateStops(array $stops, string $startTime): void
    {
        if (count($stops) < 2) {
            throw new InvalidArgumentException('A tour route needs a pickup and drop-off.');
        }
        $this->timeMinutes($startTime, 'day start time');
        $ids = [];
        foreach ($stops as $stop) {
            if (! is_array($stop) || ! is_string($stop['id'] ?? null) || trim($stop['id']) === '' || isset($ids[$stop['id']])) {
                throw new InvalidArgumentException('Every tour visit must have a unique non-empty identifier.');
            }
            $ids[$stop['id']] = true;
            foreach (['latitude' => 90, 'longitude' => 180] as $field => $limit) {
                if (! is_numeric($stop[$field] ?? null) || ! is_finite((float) $stop[$field]) || abs((float) $stop[$field]) > $limit) {
                    throw new InvalidArgumentException('Tour visit '.$stop['id'].' has invalid '.$field.'.');
                }
            }
            if (isset($stop['visit_minutes']) && (! is_numeric($stop['visit_minutes']) || ! is_finite((float) $stop['visit_minutes']) || $stop['visit_minutes'] < 0)) {
                throw new InvalidArgumentException('Tour visit '.$stop['id'].' has an invalid visit duration.');
            }
            if (isset($stop['locked']) && ! is_bool($stop['locked'])) {
                throw new InvalidArgumentException('Tour visit '.$stop['id'].' has an invalid order lock.');
            }
            foreach (['arrival_time', 'opens_at', 'closes_at'] as $field) {
                if (isset($stop[$field])) $this->timeMinutes((string) $stop[$field], $field.' for '.$stop['id']);
            }
        }
    }

    private function measure(array $stops, array &$cache, string $startTime): array
    {
        $legs = [];
        $distance = 0.0;
        $duration = 0;
        for ($index = 1; $index < count($stops); $index++) {
            $from = ['lat' => (float) $stops[$index - 1]['latitude'], 'lng' => (float) $stops[$index - 1]['longitude']];
            $to = ['lat' => (float) $stops[$index]['latitude'], 'lng' => (float) $stops[$index]['longitude']];
            $key = json_encode([$from, $to], JSON_THROW_ON_ERROR);
            if (! isset($cache[$key])) {
                $leg = $from === $to
                    ? ['distance_km' => 0.0, 'duration_seconds' => 0]
                    : $this->maps->distanceAndDuration($from, $to);
                foreach (['distance_km', 'duration_seconds'] as $field) {
                    if (! is_numeric($leg[$field] ?? null) || ! is_finite((float) $leg[$field]) || $leg[$field] < 0) {
                        throw new RuntimeException('The routing provider returned invalid '.$field.'.');
                    }
                }
                $cache[$key] = ['distance_km' => (float) $leg['distance_km'], 'duration_seconds' => (int) round($leg['duration_seconds'])];
            }
            $leg = $cache[$key];
            $legs[] = ['from_stop_id' => $stops[$index - 1]['id'], 'to_stop_id' => $stops[$index]['id']] + $leg;
            $distance += $leg['distance_km'];
            $duration += $leg['duration_seconds'];
        }
        $visitDuration = (int) round(array_sum(array_column($stops, 'visit_minutes')) * 60);
        $clock = $this->timeMinutes($startTime, 'day start time') * 60;
        $waiting = 0;
        $timingIssues = [];
        $schedule = [];
        foreach ($stops as $index => $stop) {
            if ($index > 0) $clock += $legs[$index - 1]['duration_seconds'];
            $arrival = $clock;
            $fixedArrival = isset($stop['arrival_time']) ? $this->timeMinutes($stop['arrival_time'], 'arrival time') * 60 : null;
            if ($fixedArrival !== null) {
                if ($arrival > $fixedArrival) {
                    $timingIssues[] = ['code' => 'late_arrival', 'message' => 'The estimated arrival at '.$stop['id'].' is later than its fixed arrival time.'];
                } else {
                    $waiting += $fixedArrival - $arrival;
                    $clock = $fixedArrival;
                }
            }
            $opens = isset($stop['opens_at']) ? $this->timeMinutes($stop['opens_at'], 'opening time') * 60 : null;
            $closes = isset($stop['closes_at']) ? $this->timeMinutes($stop['closes_at'], 'closing time') * 60 : null;
            if ($opens !== null && $closes !== null && $closes < $opens) $closes += 86400;
            if ($opens !== null && $clock < $opens) {
                if ($fixedArrival !== null) {
                    $timingIssues[] = ['code' => 'arrival_before_open', 'message' => 'The fixed arrival at '.$stop['id'].' is before its opening time.'];
                } else {
                    $waiting += $opens - $clock;
                    $clock = $opens;
                }
            }
            $visitSeconds = (int) round(((float) ($stop['visit_minutes'] ?? 0)) * 60);
            if ($closes !== null && $clock + $visitSeconds > $closes) {
                $timingIssues[] = ['code' => 'outside_opening_hours', 'message' => 'The planned visit at '.$stop['id'].' would finish after its closing time.'];
            }
            $schedule[] = [
                'stop_id' => $stop['id'],
                'stop_name' => $stop['name'] ?? 'Tour stop',
                'estimated_arrival' => $this->formatTime((int) floor($clock / 60)),
                'opens_at' => $stop['opens_at'] ?? null,
                'closes_at' => $stop['closes_at'] ?? null,
            ];
            $clock += $visitSeconds;
        }
        if ($clock > 86400) {
            $timingIssues[] = ['code' => 'day_duration_exceeded', 'message' => 'Travel and visits exceed one local calendar day. Review the itinerary split.'];
        }

        return [
            'stops' => $stops,
            'legs' => $legs,
            'start_time' => $startTime,
            'distance_km' => round($distance, 3),
            'duration_seconds' => $duration,
            'visit_duration_seconds' => $visitDuration,
            'waiting_duration_seconds' => $waiting,
            'total_duration_seconds' => $duration + $visitDuration + $waiting,
            'schedule' => $schedule,
            'timing_issues' => $timingIssues,
        ];
    }

    private function timeMinutes(string $value, string $label): int
    {
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
            throw new InvalidArgumentException('Invalid '.$label.'. Use a local 24-hour time.');
        }
        [$hour, $minute] = array_map('intval', explode(':', $value));

        return $hour * 60 + $minute;
    }

    private function formatTime(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
    }
}
