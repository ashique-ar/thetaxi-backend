<?php

use Tests\TestCase;

uses(TestCase::class);

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
    $migration = file_get_contents(database_path('migrations/2026_07_25_000001_harden_vehicle_lease_release_settlements.php'));

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
        ->toContain('settlement_idempotency_key');
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
