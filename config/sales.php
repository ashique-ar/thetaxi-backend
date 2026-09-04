<?php

return [
    // Deployment-level activation gates only. Business policy values (FX correction policy, commission
    // dispute response window, profile-export retention, business timezone, approved Sales staff
    // categories) are governed, database-backed records created and approved by authorized users in
    // the running application — see App\Services\Sales\SalesPolicySettingsService and the Sales staff
    // category register, not this file. Flipping one of these flags is a deploy-level review, not a
    // Finance/Sales admin action, so it stays here rather than becoming an in-app toggle.
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
];
