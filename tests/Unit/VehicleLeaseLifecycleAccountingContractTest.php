<?php

use Tests\TestCase;

it('keeps vehicle-owner commitments independent from finance and lease lifecycle actions', function () {
    $service = file_get_contents(app_path('Services/VehicleLeaseService.php'));

    expect($service)
        ->not->toContain("'monthly_payment_commitment' =>")
        ->toContain('$firstDueDate->copy()->addMonthsNoOverflow')
        ->toContain('This payment key was already used with different payment details.');
});

it('requires complete and replay-safe release settlement evidence', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Vehicle/VehicleLeaseController.php'));
    $service = file_get_contents(app_path('Services/VehicleLeaseService.php'));
    $migration = file_get_contents(database_path('migrations/2026_07_24_000001_harden_vehicle_lease_release_settlements.php'));

    expect($controller)
        ->toContain("'direction' =>")
        ->toContain("'amount' =>")
        ->toContain("'payment_method' =>")
        ->toContain("'idempotency_key' =>")
        ->and($service)
        ->toContain('payable_to_provider')
        ->toContain('receivable_from_provider')
        ->toContain('The settlement amount must equal the approved net release settlement.')
        ->toContain('This settlement key was already used with different settlement details.')
        ->and($migration)
        ->toContain('settlement_direction')
        ->toContain('settlement_amount')
        ->toContain('settlement_method')
        ->toContain('settlement_idempotency_key')
        ->toContain('legacy_unverified');
});

it('protects embedded current lease edits from stale forms and unauthorized disclosure', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Vehicle/VehicleController.php'));
    $resource = file_get_contents(app_path('Http/Resources/Vehicle/VehicleResource.php'));
    $service = file_get_contents(app_path('Services/VehicleLeaseService.php'));

    expect($controller)
        ->toContain('A current contract was created or changed after this vehicle form was opened.')
        ->and($service)
        ->toContain('Vehicle::query()->lockForUpdate()->findOrFail($vehicle->id)')
        ->and($resource)
        ->toContain("can('vehicle-leases.view')");
});

it('keeps final payment retries and refundable deposit closure controls replay safe', function () {
    $service = file_get_contents(app_path('Services/VehicleLeaseService.php'));
    $duplicateLookup = strpos($service, '$duplicate = VehicleLeasePayment::query()');
    $lifecycleGuard = strpos($service, 'Payments can be recorded only for active or expired leases.');

    expect($duplicateLookup)->not->toBeFalse()
        ->and($lifecycleGuard)->not->toBeFalse()
        ->and($duplicateLookup)->toBeLessThan($lifecycleGuard)
        ->and($service)
        ->toContain('optionalText($duplicate->reference)')
        ->toContain('recordDepositDisposition')
        ->toContain('reverseDepositDisposition')
        ->toContain('Return, forfeit, or offset the remaining refundable deposit before closing finance.');
});

it('gates lease dashboard figures and resolves partial periods before projection', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Vehicle/VehicleController.php'));

    expect($controller)
        ->toContain("can('vehicle-leases.view')")
        ->toContain('$providedStart')
        ->toContain('$providedEnd')
        ->toContain('The end date must be on or after the start date.')
        ->toContain('...($canViewLeaseAccounting ? [');
});
