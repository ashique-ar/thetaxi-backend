<?php

use App\Models\Driver\RoutePoint;
use App\Models\DriverAssignment;
use App\Services\Driver\HistoricalRouteEvidenceAuditService;
use Illuminate\Database\Eloquent\Collection;

uses(Tests\TestCase::class);

it('detects historical route risks without changing raw evidence or legacy distance', function () {
    $assignment = new DriverAssignment([
        'booking_id' => 'booking-1',
        'booking_item_id' => 'item-1',
        'trip_started_at' => '2026-01-01 10:00:00',
        'trip_completed_at' => '2026-01-02 11:00:00',
        'total_distance_km' => 52.61,
    ]);
    $assignment->id = 'assignment-1';
    $points = new Collection([
        new RoutePoint(['latitude' => 6.9271, 'longitude' => 79.8612, 'accuracy' => 5, 'recorded_at' => '2026-01-01 09:00:00']),
        new RoutePoint(['latitude' => 6.9272, 'longitude' => 79.8613, 'accuracy' => 5, 'recorded_at' => '2026-01-01 10:01:00']),
        new RoutePoint(['latitude' => 6.9300, 'longitude' => 79.8700, 'accuracy' => 5, 'recorded_at' => '2026-01-02 10:30:00']),
    ]);
    $assignment->setRelation('routePoints', $points);
    $before = $points->map->getAttributes()->all();

    $report = app(HistoricalRouteEvidenceAuditService::class)->inspect($assignment);

    expect($report['issue_codes'])->toContain('cross_day_points', 'outside_lifecycle_window', 'long_gps_gap', 'legacy_calculator_version_unknown', 'legacy_distance_value_present')
        ->and($report['legacy_distance_km'])->toBe(52.61)
        ->and($report['mutation_performed'])->toBeFalse()
        ->and($report['pricing_effect'])->toBe('none')
        ->and($report['raw_evidence_fingerprint'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($report['calculation_version'])->not->toBeEmpty()
        ->and($points->map->getAttributes()->all())->toBe($before);
});

it('registers a report-only command with no correction option', function () {
    $command = file_get_contents(app_path('Console/Commands/AuditHistoricalRouteEvidence.php'));
    expect($command)->toContain('bookings:audit-route-evidence')
        ->toContain("'read_only' => true")
        ->toContain("'corrections_authorized' => false")
        ->toContain("'calculation_policy_version' => config('route_evidence.policy_version')")
        ->not->toContain('save(')
        ->not->toContain('update(')
        ->not->toContain('delete(');
});
