<?php

return [
    'system_user_id' => env('HR_SYSTEM_USER_ID'),
    'report_artifact_retention_days' => env('HR_REPORT_ARTIFACT_RETENTION_DAYS', 30),
    'notification_max_attempts' => env('HR_NOTIFICATION_MAX_ATTEMPTS', 5),
    'notification_lease_seconds' => env('HR_NOTIFICATION_LEASE_SECONDS', 120),
    'notification_retry_max_minutes' => env('HR_NOTIFICATION_RETRY_MAX_MINUTES', 60),
    'safety_external_max_attempts' => env('HR_SAFETY_EXTERNAL_MAX_ATTEMPTS', 5),
    'wellness_consent_notice_version' => env('HR_WELLNESS_CONSENT_NOTICE_VERSION'),
    'features' => [
        'people_core' => env('HR_PEOPLE_CORE_ENABLED', false),
        'attendance_ingestion' => env('HR_ATTENDANCE_INGESTION_ENABLED', false),
        'physical_access_commands' => env('HR_PHYSICAL_ACCESS_COMMANDS_ENABLED', false),
        'attendance_results' => env('HR_ATTENDANCE_RESULTS_ENABLED', false),
        'leave_overtime' => env('HR_LEAVE_OVERTIME_ENABLED', false),
        'payroll' => env('HR_PAYROLL_ENABLED', false),
        'employee_self_service' => env('HR_EMPLOYEE_SELF_SERVICE_ENABLED', false),
        'talent' => env('HR_TALENT_ENABLED', false),
        'learning' => env('HR_LEARNING_ENABLED', false),
        'service_operations' => env('HR_SERVICE_OPERATIONS_ENABLED', false),
        'advanced_assets' => env('HR_ADVANCED_ASSETS_ENABLED', false),
        'travel' => env('HR_TRAVEL_ENABLED', false),
        'knowledge' => env('HR_KNOWLEDGE_ENABLED', false),
        'meals' => env('HR_MEALS_ENABLED', false),
        'relations_safety' => env('HR_RELATIONS_SAFETY_ENABLED', false),
        'engagement_analytics' => env('HR_ENGAGEMENT_ANALYTICS_ENABLED', false),
    ],
];
