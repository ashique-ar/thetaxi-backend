<?php

namespace App\Services\Tours;

use App\Services\GoogleMapsService;
use InvalidArgumentException;
use RuntimeException;

class TourRoutePlanner
{
    public function __construct(private readonly GoogleMapsService $maps) {}

    public function suggestDays(array $days): array
    {
        // shortcut: day-aware search caps at 256 route legs and 256 candidate plans to bound Maps costs.
        $days = array_values($days);
        $cache = [];
        $protectedStops = [];
        foreach ($days as $day) {
            foreach ($day['visits'] as $position => $visit) {
                if (($visit['locked'] ?? false) || isset($visit['arrival_time'])) {
                    $protectedStops[$visit['id']] = [(int) $day['day_number'], $position];
                }
            }
        }
        $evaluate = function (array $groups) use (&$cache): array {
            $plans = [];
            $issues = [];
            $distance = 0.0;
            $duration = 0;
            foreach ($groups as $index => $day) {
                $route = $this->measure([$day['start'], ...$day['visits'], $day['end']], $cache, $day['start_time'], 256);
                foreach ($route['timing_issues'] as $issue) $issues[] = $issue + ['day_number' => $day['day_number']];
                $distance += $route['distance_km'];
                $duration += $route['duration_seconds'];
                $plans[$index] = $route;
            }

            return ['plans' => $plans, 'issues' => $issues, 'distance_km' => round($distance, 3), 'duration_seconds' => $duration];
        };

        try {
            $original = $evaluate($days);
            $bestGroups = $days;
            $best = $original;
            $candidateCount = 0;
            $maxPasses = max(1, array_sum(array_map(fn(array $day): int => count($day['visits']), $days)));
            for ($pass = 0; $pass < $maxPasses && $candidateCount < 256 && count($cache) < 256; $pass++) {
                $nextGroups = null;
                $next = null;
                $searchLimitReached = false;
                foreach ($bestGroups as $sourceIndex => $source) {
                    if ($source['locked']) continue;
                    foreach ($source['visits'] as $visitIndex => $visit) {
                        if (($visit['locked'] ?? false) || isset($visit['arrival_time'])) continue;
                        $groupId = $visit['group_id'] ?? $visit['id'];
                        $groupStart = array_search($groupId, array_column($source['visits'], 'group_id'), true);
                        if ($groupStart !== false && $groupStart !== $visitIndex) continue;
                        $block = array_values(array_filter($source['visits'], fn(array $candidate): bool => ($candidate['group_id'] ?? $candidate['id']) === $groupId));
                        if (collect($block)->contains(fn(array $candidate): bool => ($candidate['locked'] ?? false) || isset($candidate['arrival_time']))) continue;
                        $targetOrder = array_values(array_filter(array_keys($bestGroups), fn(int $index): bool => $index !== $sourceIndex));
                        $targetOrder[] = $sourceIndex;
                        foreach ($targetOrder as $targetIndex) {
                            $target = $bestGroups[$targetIndex];
                            if ($target['locked']) continue;
                            $remaining = array_values(array_filter($source['visits'], fn(array $candidate): bool => ($candidate['group_id'] ?? $candidate['id']) !== $groupId));
                            $targetVisits = $sourceIndex === $targetIndex ? $remaining : $target['visits'];
                            $positions = range(0, count($targetVisits));
                            foreach ($positions as $position) {
                                if ($sourceIndex === $targetIndex && $position === $visitIndex) continue;
                                if (++$candidateCount > 256 || count($cache) >= 256) { $searchLimitReached = true; break 4; }
                                $candidateGroups = $bestGroups;
                                $candidateGroups[$sourceIndex]['visits'] = $remaining;
                                array_splice($candidateGroups[$targetIndex]['visits'], $position, 0, $block);
                                if (!$this->preservesProtectedStops($candidateGroups, $protectedStops)) continue;
                                try {
                                    $candidate = $evaluate($candidateGroups);
                                } catch (\LengthException) {
                                    $searchLimitReached = true;
                                    break 4;
                                }
                                if ($this->isBetterDayPlan($candidate, $next ?? $best)) {
                                    $next = $candidate;
                                    $nextGroups = $candidateGroups;
                                }
                            }
                        }
                    }
                }
                if (!$nextGroups || !$next || !$this->isBetterDayPlan($next, $best)) break;
                $bestGroups = $nextGroups;
                $best = $next;
                if ($searchLimitReached) break;
            }

            $dayPlans = [];
            foreach ($days as $index => $day) {
                $suggestedIndex = array_search($day['day_number'], array_column($bestGroups, 'day_number'), true);
                $suggestedDay = $suggestedIndex === false ? $index : $suggestedIndex;
                $suggestedIds = array_column($bestGroups[$suggestedDay]['visits'], 'id');
                $dayPlans[] = [
                    'day_number' => $day['day_number'],
                    'start_time' => $day['start_time'],
                    'original' => $original['plans'][$index],
                    'suggested' => $best['plans'][$suggestedDay],
                    'suggested_stop_ids' => $suggestedIds,
                    'changed' => array_column($day['visits'], 'id') !== $suggestedIds,
                    'issues' => array_values(array_filter($best['issues'], fn(array $issue): bool => ($issue['day_number'] ?? null) === $day['day_number'])),
                ];
            }

            return [
                'days' => $dayPlans,
                'status' => $best['issues'] === [] ? 'ready' : 'review_required',
                'issues' => $best['issues'],
                'changed' => $dayPlans !== [] && collect($dayPlans)->contains(fn(array $day): bool => $day['changed']),
            ];
        } catch (\Throwable) {
            $unavailable = array_map(function (array $day): array {
                $route = ['stops' => [$day['start'], ...$day['visits'], $day['end']], 'legs' => [], 'distance_km' => null, 'duration_seconds' => null, 'visit_duration_seconds' => 0, 'waiting_duration_seconds' => 0, 'total_duration_seconds' => null, 'schedule' => [], 'timing_issues' => []];
                return ['day_number' => $day['day_number'], 'start_time' => $day['start_time'], 'original' => $route, 'suggested' => $route, 'suggested_stop_ids' => array_column($day['visits'], 'id'), 'changed' => false, 'issues' => []];
            }, $days);

            return ['days' => $unavailable, 'status' => 'unavailable', 'issues' => [['code' => 'route_unavailable', 'message' => 'We could not verify a shorter route. Keep your selected route or ask our team to review it.']], 'changed' => false];
        }
    }

    private function preservesProtectedStops(array $days, array $protectedStops): bool
    {
        foreach ($days as $day) {
            foreach ($day['visits'] as $position => $visit) {
                if (isset($protectedStops[$visit['id']]) && $protectedStops[$visit['id']] !== [(int) $day['day_number'], $position]) return false;
            }
        }

        return true;
    }

    private function isBetterDayPlan(array $candidate, array $current): bool
    {
        if (count($candidate['issues']) < count($current['issues'])) return true;
        if (count($candidate['issues']) > count($current['issues'])) return false;
        $shorter = $candidate['distance_km'] < $current['distance_km']
            && $candidate['duration_seconds'] <= $current['duration_seconds'];
        $faster = $candidate['duration_seconds'] < $current['duration_seconds']
            && $candidate['distance_km'] <= $current['distance_km'];

        return $shorter || $faster;
    }

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
            if (array_key_exists('place_id', $stop) && (! is_string($stop['place_id']) || trim($stop['place_id']) === '')) {
                throw new InvalidArgumentException('Tour visit '.$stop['id'].' has an invalid place_id.');
            }
            if (empty($stop['place_id'])) {
                foreach (['latitude' => 90, 'longitude' => 180] as $field => $limit) {
                    if (! is_numeric($stop[$field] ?? null) || ! is_finite((float) $stop[$field]) || abs((float) $stop[$field]) > $limit) {
                        throw new InvalidArgumentException('Tour visit '.$stop['id'].' has invalid '.$field.'.');
                    }
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

    private function measure(array $stops, array &$cache, string $startTime, int $maxUniqueLegs = PHP_INT_MAX): array
    {
        $legs = [];
        $distance = 0.0;
        $duration = 0;
        for ($index = 1; $index < count($stops); $index++) {
            $from = ! empty($stops[$index - 1]['place_id'])
                ? ['place_id' => $stops[$index - 1]['place_id']]
                : ['lat' => (float) $stops[$index - 1]['latitude'], 'lng' => (float) $stops[$index - 1]['longitude']];
            $to = ! empty($stops[$index]['place_id'])
                ? ['place_id' => $stops[$index]['place_id']]
                : ['lat' => (float) $stops[$index]['latitude'], 'lng' => (float) $stops[$index]['longitude']];
            $key = json_encode([$from, $to], JSON_THROW_ON_ERROR);
            if (! isset($cache[$key])) {
                if (count($cache) >= $maxUniqueLegs) throw new \LengthException('Route lookup limit reached.');
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
