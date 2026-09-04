<?php

it('keeps commission notification delivery default off and migration rollback evidence safe', function () {
    $config = file_get_contents(config_path('sales.php'));
    $environment = file_get_contents(base_path('.env.example'));
    $migration = file_get_contents(database_path('migrations/2026_08_13_141000_create_sales_commission_notification_governance.php'));

    expect($config)->toContain("'commission_notifications' => env('SALES_COMMISSION_NOTIFICATIONS_ENABLED', false)")
        ->and($environment)->toContain('SALES_COMMISSION_NOTIFICATIONS_ENABLED=false')
        ->and($migration)->toContain('sales_commission_notification_policy_versions')
        ->toContain('sales_commission_notification_policy_checker')
        ->toContain("channel = 'in_app'")
        ->toContain('sales_commission_notification_preferences')
        ->toContain('sales_commission_notification_deliveries')
        ->toContain('sales_commission_notification_delivery_events')
        ->toContain('Refusing to drop {$table} while governed notification evidence exists.');
});

it('requires approved effective policy checksum explicit preference and current beneficiary identity', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesCommissionNotificationService.php'));

    expect($service)->toContain("where('status', 'approved')")
        ->toContain("whereNotNull('approved_by')")
        ->toContain('validPolicyChecksum')
        ->toContain('approved_policy_ambiguous')
        ->toContain('explicit_preference_missing')
        ->toContain("where('profile.commission_eligible', true)")
        ->toContain("whereNull('staff.employment_ended_at')")
        ->toContain("where('user.is_active', true)")
        ->toContain('recipient_scope_changed');
});

it('minimizes the delivered payload and retries idempotently through immutable audit evidence', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesCommissionNotificationService.php'));
    $command = file_get_contents(app_path('Console/Commands/ProcessSalesCommissionNotifications.php'));
    $relay = file_get_contents(app_path('Jobs/Foundation/RelayDomainOutboxEvent.php'));

    expect($service)->toContain("'action_path' => '/sales/commission-holds'")
        ->not->toContain("'booking_id' =>")
        ->not->toContain("'commission_amount_lkr' =>")
        ->toContain('deterministicUuid')
        ->toContain("'status' => 'delivered'")
        ->toContain("'retry_scheduled'")
        ->toContain('sales_commission_notification_delivery_events')
        ->and($command)->toContain('{--commit}')
        ->toContain('No writes performed.')
        ->and($relay)->toContain("\$domainSubscribers[\$row->event_type]");
});

it('exposes only delivery state on the already scoped hold response', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionHoldController.php'));

    expect($controller)->toContain("setAttribute('notification_delivery'")
        ->toContain("'sales.commission-decisions.view-all'")
        ->toContain("'sales.commission-decisions.view-team'")
        ->not->toContain('recipient_user_id');
});
