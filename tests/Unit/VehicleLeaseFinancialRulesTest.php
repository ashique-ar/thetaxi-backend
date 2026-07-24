<?php

use App\Services\VehicleLeaseService;
use App\Models\Vehicle\VehicleLease;
use Illuminate\Validation\ValidationException;

uses(Tests\TestCase::class);

function validateVehicleLeaseTerms(array $overrides = []): void
{
    $terms = array_merge([
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'first_payment_date' => '2026-01-31',
        'financed_amount' => 120000,
        'installment_amount' => 10000,
        'installment_count' => 12,
        'payment_frequency' => 'monthly',
        'balloon_payment' => 0,
        'refundable_deposit' => 15000,
        'deposit_paid_amount' => 15000,
        'deposit_paid_date' => '2026-01-01',
        'deposit_payment_method' => 'bank_transfer',
        'deposit_payment_reference' => 'DEP-001',
    ], $overrides);

    $method = new ReflectionMethod(VehicleLeaseService::class, 'validateFinancialTerms');
    $method->invoke(new VehicleLeaseService(), $terms);
}

it('accepts a reconciled lease schedule and separately evidenced refundable deposit', function () {
    validateVehicleLeaseTerms();

    expect(true)->toBeTrue();
});

it('rejects an instalment schedule below the financed principal', function () {
    expect(fn () => validateVehicleLeaseTerms([
        'installment_amount' => 9000,
    ]))->toThrow(ValidationException::class);
});

it('rejects a paid refundable deposit above the contractual deposit', function () {
    expect(fn () => validateVehicleLeaseTerms([
        'deposit_paid_amount' => 16000,
    ]))->toThrow(ValidationException::class);
});

it('rejects an instalment schedule whose final due date exceeds the lease period', function () {
    expect(fn () => validateVehicleLeaseTerms([
        'end_date' => '2026-06-30',
    ]))->toThrow(ValidationException::class);
});

it('normalizes non-monthly instalments to the vehicle monthly commitment', function (
    string $frequency,
    float $installment,
    float $expected
) {
    $lease = new VehicleLease([
        'payment_frequency' => $frequency,
        'installment_amount' => $installment,
    ]);
    $method = new ReflectionMethod(VehicleLeaseService::class, 'monthlyCommitment');

    expect($method->invoke(new VehicleLeaseService(), $lease))->toBe($expected);
})->with([
    'monthly' => ['monthly', 12000, 12000.0],
    'quarterly' => ['quarterly', 36000, 12000.0],
    'semiannual' => ['semiannual', 72000, 12000.0],
    'annual' => ['annual', 144000, 12000.0],
]);
