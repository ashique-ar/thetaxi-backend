<?php

$profileExportRetentionInput = env('SALES_PROFILE_EXPORT_RETENTION_DAYS');
$profileExportRetentionDays = filter_var(
    $profileExportRetentionInput,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]],
);
$fxRateMaxAgeInput = env('SALES_FX_RATE_MAX_AGE_HOURS');
$fxRateMaxAgeHours = filter_var($fxRateMaxAgeInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$fxRoundingScaleInput = env('SALES_FX_ROUNDING_SCALE');
$fxRoundingScale = filter_var($fxRoundingScaleInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4]]);
$staffCategories = collect(explode(',', (string) env('SALES_STAFF_CATEGORIES', '')))
    ->map(fn (string $category) => trim($category))
    ->filter()
    ->unique(fn (string $category) => mb_strtolower($category))
    ->values()
    ->all();

return [
    // Required before period close so local calendar boundaries can be frozen to exact UTC instants.
    'business_timezone' => env('SALES_BUSINESS_TIMEZONE') ?: null,
    'features' => [
        'sales_profiles' => env('SALES_PROFILES_ENABLED', false),
        'canonical_receipts_v2' => env('SALES_CANONICAL_RECEIPTS_V2_ENABLED', false),
        'rolling_payment_schedules' => env('SALES_ROLLING_PAYMENT_SCHEDULES_ENABLED', false),
        'enforce_payment_finality' => env('SALES_PAYMENT_FINALITY_ENFORCED', false),
        'commission_shadow' => env('SALES_COMMISSION_SHADOW_ENABLED', false),
        'commission_accrual' => env('SALES_COMMISSION_ACCRUAL_ENABLED', false),
        'commission_notifications' => env('SALES_COMMISSION_NOTIFICATIONS_ENABLED', false),
        'statements' => env('SALES_COMMISSION_STATEMENTS_ENABLED', false),
        'payouts' => env('SALES_COMMISSION_PAYOUTS_ENABLED', false),
        'management_dashboard' => env('SALES_MANAGEMENT_DASHBOARD_ENABLED', false),
        'self_dashboard' => env('SALES_SELF_DASHBOARD_ENABLED', false),
        'crm' => env('SALES_CRM_ENABLED', false),
        'performance_snapshots' => env('SALES_PERFORMANCE_SNAPSHOTS_ENABLED', false),
        'performance_alert_evaluations' => env('SALES_PERFORMANCE_ALERT_EVALUATIONS_ENABLED', false),
        'performance_alert_actions' => env('SALES_PERFORMANCE_ALERT_ACTIONS_ENABLED', false),
        'fx_corrections' => env('SALES_FX_CORRECTIONS_ENABLED', false),
    ],
    'disputes' => [
        'response_days' => env('SALES_COMMISSION_DISPUTE_RESPONSE_DAYS', 7),
    ],
    // Sales remains a Staff category, but category membership alone never grants a Sales Profile or eligibility.
    'staff_categories' => $staffCategories,
    'profile_exports' => [
        // A reviewed retention value is mandatory before roster exports can be generated.
        'retention_days' => $profileExportRetentionDays === false ? null : $profileExportRetentionDays,
    ],
    'fx_corrections' => [
        // Finance must explicitly approve these values before reporting-FX corrections can be recorded.
        'approved_quote_base' => env('SALES_FX_APPROVED_QUOTE_BASE') ?: null,
        'calculation_mode' => env('SALES_FX_CALCULATION_MODE') ?: null,
        'max_rate_age_hours' => $fxRateMaxAgeHours === false ? null : $fxRateMaxAgeHours,
        'rounding_scale' => $fxRoundingScale === false ? null : $fxRoundingScale,
    ],
];
