<?php

it('uses a scoped and single-use claim selector for travel settlement', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/TravelController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $migration = file_get_contents(base_path('database/migrations/2026_10_02_000001_make_travel_settlement_claim_single_use.php'));
    $dialog = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-talent/components/assets-travel/dialogs/travel-settle-dialog.component.ts'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-talent/components/assets-travel/assets-travel.component.ts'));

    expect($routes)->toContain("Route::get('requests/{id}/settlement-claim-options', [TravelController::class, 'settlementClaimOptions'])")
        ->and($controller)->toContain("where('claim.staff_id', \$travel->staff_id)")
        ->toContain("where('claim.currency', \$travel->currency)")
        ->toContain("whereNotNull('claim.approved_amount')")
        ->toContain('Travel expense claim is already linked to another settlement.')
        ->toContain('lockForUpdate()')
        ->and($migration)->toContain("->unique('settlement_claim_id'")
        ->and($dialog)->toContain('settlement-claim-options')->toContain('UiManagedRecordSelectComponent')
        ->not->toContain('data.travelClaims')
        ->and($component)->not->toContain('this.api.claims()');
});
