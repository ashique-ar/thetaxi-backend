<?php

return [
    'route_usage' => [
        'enabled' => (bool) env('BOOKING_ROUTE_USAGE_ENABLED', false),
        'starts_at' => env('BOOKING_ROUTE_USAGE_STARTS_AT'),
        'ends_at' => env('BOOKING_ROUTE_USAGE_ENDS_AT'),
    ],
    'route_retention_days' => env('BOOKING_ROUTE_RETENTION_DAYS') !== null
        ? (int) env('BOOKING_ROUTE_RETENTION_DAYS')
        : null,
    'route_export_max_points' => (int) env('BOOKING_ROUTE_EXPORT_MAX_POINTS', 10000),
    'access_log_throttle_seconds' => (int) env('BOOKING_ROUTE_ACCESS_LOG_THROTTLE_SECONDS', 300),
    'health_alert_throttle_seconds' => (int) env('BOOKING_HEALTH_ALERT_THROTTLE_SECONDS', 900),
    'performance' => [
        'read_target_ms' => (int) env('BOOKING_OPERATIONS_READ_TARGET_MS', 2000),
        'action_target_ms' => (int) env('BOOKING_OPERATIONS_ACTION_TARGET_MS', 3000),
    ],
];
