<?php

return [
    'system_user_id' => env('HR_SYSTEM_USER_ID'),
    'report_artifact_retention_days' => env('HR_REPORT_ARTIFACT_RETENTION_DAYS', 30),
    'notification_max_attempts' => env('HR_NOTIFICATION_MAX_ATTEMPTS', 5),
    'notification_lease_seconds' => env('HR_NOTIFICATION_LEASE_SECONDS', 120),
    'notification_retry_max_minutes' => env('HR_NOTIFICATION_RETRY_MAX_MINUTES', 60),
    'safety_external_max_attempts' => env('HR_SAFETY_EXTERNAL_MAX_ATTEMPTS', 5),
    'wellness_consent_notice_version' => env('HR_WELLNESS_CONSENT_NOTICE_VERSION'),
    'hikvision' => [
        'connect_timeout_seconds' => env('HR_HIKVISION_CONNECT_TIMEOUT_SECONDS', 3),
        'request_timeout_seconds' => env('HR_HIKVISION_REQUEST_TIMEOUT_SECONDS', 10),
    ],
];
