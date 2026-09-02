<?php

namespace App\Services\Driver;

use App\Models\DriverAssignment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class HistoricalRouteEvidenceAuditService
{
    public function __construct(private readonly RouteEvidenceService $evidence) {}

    public function inspect(DriverAssignment $assignment): array
    {
        $points = $assignment->relationLoaded('routePoints') ? $assignment->routePoints : $assignment->routePoints()->get();
        $result = $this->evidence->calculate($points, $assignment->trip_started_at, $assignment->trip_completed_at);
        $dates = $points->map(fn ($point) => Carbon::parse($point->recorded_at)->utc()->toDateString())->unique();
        $outsideWindow = $points->filter(function ($point) use ($assignment) {
            $at = Carbon::parse($point->recorded_at)->utc();
            return !$assignment->trip_started_at || $at->lt($assignment->trip_started_at)
                || ($assignment->trip_completed_at && $at->gt($assignment->trip_completed_at));
        })->count();
        $issues = array_values(array_filter([
            $dates->count() > 1 ? 'cross_day_points' : null,
            $outsideWindow > 0 ? 'outside_lifecycle_window' : null,
            $result['gap_count'] > 0 ? 'long_gps_gap' : null,
            !$result['distance_trustworthy'] ? 'insufficient_or_untrusted_coverage' : null,
            $assignment->total_distance_km !== null ? 'legacy_calculator_version_unknown' : null,
            $assignment->total_distance_km !== null ? 'legacy_distance_value_present' : null,
        ]));

        return [
            'booking_id' => (string) $assignment->booking_id,
            'booking_item_id' => $assignment->booking_item_id ? (string) $assignment->booking_item_id : null,
            'assignment_id' => (string) $assignment->id,
            'issue_codes' => $issues,
            'legacy_distance_km' => $assignment->total_distance_km !== null ? (float) $assignment->total_distance_km : null,
            'evidence' => $result,
            'raw_point_count' => $points->count(),
            'raw_evidence_fingerprint' => $this->evidenceFingerprint($points),
            'calculation_version' => $result['calculation_version'] ?? null,
            'outside_window_point_count' => $outsideWindow,
            'mutation_performed' => false,
            'pricing_effect' => 'none',
        ];
    }

    private function evidenceFingerprint(Collection $points): string
    {
        $canonical = $points
            ->sortBy(fn ($point) => sprintf('%s|%s', Carbon::parse($point->recorded_at)->utc()->format('Y-m-d\TH:i:s.u\Z'), $point->id ?? ''))
            ->map(fn ($point) => implode('|', [
                (string) ($point->id ?? ''),
                Carbon::parse($point->recorded_at)->utc()->format('Y-m-d\TH:i:s.u\Z'),
                number_format((float) $point->latitude, 7, '.', ''),
                number_format((float) $point->longitude, 7, '.', ''),
                $point->accuracy === null ? '' : number_format((float) $point->accuracy, 2, '.', ''),
            ]))
            ->implode("\n");

        return hash('sha256', $canonical);
    }
}
