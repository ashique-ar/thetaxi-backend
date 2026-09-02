<?php

namespace App\Support;

final class DriverRouteEvidenceContract
{
    public const CLASSIFICATIONS = [
        'recorded_raw', 'recorded_validated', 'map_matched', 'inferred_gap',
        'estimated_route', 'odometer_verified', 'verified_manual', 'unavailable',
    ];

    public const REJECTION_REASONS = [
        'duplicate', 'invalid_coordinate', 'accuracy_too_poor', 'timestamp_in_future',
        'timestamp_too_old', 'outside_session_window', 'outside_assignment_window',
        'session_context_mismatch', 'assignment_context_mismatch', 'out_of_order',
        'implausible_movement',
    ];

    public const COVERAGE_STATUSES = ['healthy', 'partial', 'insufficient', 'invalid', 'not_recorded'];
    public const CALCULATION_VERSION = 'contiguous-v2';
    public const PRICING_EFFECT = 'none';

    private function __construct() {}
}
