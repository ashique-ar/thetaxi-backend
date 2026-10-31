<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('does not expose saved-location selectors for installation-wide Sales settings', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo([
        'sales.attributions.view', 'sales.attributions.view-all',
        'sales.payment-adjustments.create', 'sales.payment-adjustments.create-all',
        'sales.payment-finality.manage', 'sales.payment-finality.manage-all',
        'sales.policy-settings.view', 'sales.policy-settings.manage-all',
        'sales.commission-config.view', 'sales.commission-config.manage-all',
        'sales.performance.view', 'sales.performance.view-all',
    ]);

    foreach ([
        '/api/sales/performance/company-options',
        '/api/sales/commission-configuration/company-options',
        '/api/sales/payment-adjustment-company-options',
        '/api/sales/attribution-company-options',
    ] as $endpoint) {
        actingAs($admin, 'api')->getJson($endpoint)->assertNotFound();
    }
});
