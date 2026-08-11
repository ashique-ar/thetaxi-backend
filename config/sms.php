<?php

return [
    'health' => [
        'queue_age_minutes' => max(1, (int) env('SMS_ALERT_QUEUE_AGE_MINUTES', 10)),
        'callback_age_minutes' => max(1, (int) env('SMS_ALERT_CALLBACK_AGE_MINUTES', 30)),
        'failure_window_minutes' => max(5, (int) env('SMS_ALERT_FAILURE_WINDOW_MINUTES', 60)),
        'failure_count' => max(1, (int) env('SMS_ALERT_FAILURE_COUNT', 5)),
        'messages_per_booking' => max(3, (int) env('SMS_ALERT_MESSAGES_PER_BOOKING', 5)),
        'low_balance' => max(0, (float) env('SMS_ALERT_LOW_BALANCE', 1000)),
    ],
    'retention' => [
        'enabled' => (bool) env('SMS_RETENTION_ENABLED', false),
        'content_days' => max(30, (int) env('SMS_CONTENT_RETENTION_DAYS', 90)),
        'provider_payload_days' => max(7, (int) env('SMS_PROVIDER_PAYLOAD_RETENTION_DAYS', 30)),
    ],
];
