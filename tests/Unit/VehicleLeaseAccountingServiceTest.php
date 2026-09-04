<?php

use App\Models\Vehicle\VehicleLease;
use App\Models\Vehicle\VehicleLeaseDepositDisposition;
use App\Models\Vehicle\VehicleLeasePayment;
use App\Models\Vehicle\VehicleLeasePaymentAllocation;
use App\Models\Vehicle\VehicleLeaseRelease;
use App\Models\Vehicle\VehicleLeaseSchedule;
use App\Services\VehicleLeaseAccountingService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

afterEach(function () {
    Carbon::setTestNow();
});

function vehicleLeaseAccountingPayment(
    string $id,
    float|string $amount,
    string $paidDate,
    string $status = 'recorded',
    ?string $reversedAt = null
): VehicleLeasePayment {
    $payment = new VehicleLeasePayment([
        'amount' => $amount,
        'paid_date' => $paidDate,
        'payment_method' => 'bank_transfer',
        'status' => $status,
        'reversed_at' => $reversedAt,
    ]);
    $payment->id = $id;
    $payment->setRelation('allocations', collect());

    return $payment;
}

function vehicleLeaseAccountingAllocation(
    float|string $amount,
    VehicleLeasePayment $payment,
    ?string $reversedAt = null
): VehicleLeasePaymentAllocation {
    $allocation = new VehicleLeasePaymentAllocation([
        'amount' => $amount,
        'allocated_at' => '2026-08-10 10:00:00',
        'reversed_at' => $reversedAt,
    ]);
    $allocation->setRelation('payment', $payment);

    return $allocation;
}

function vehicleLeaseAccountingSchedule(
    string $dueDate,
    float|string $amount,
    array $allocations = []
): VehicleLeaseSchedule {
    $schedule = new VehicleLeaseSchedule([
        'due_date' => $dueDate,
        'amount_due' => $amount,
        'status' => 'scheduled',
    ]);
    $schedule->setRelation('allocations', collect($allocations));

    return $schedule;
}

function vehicleLeaseAccountingLease(
    string $id,
    string $vehicleId,
    array $overrides = [],
    array $schedules = [],
    array $payments = [],
    ?VehicleLeaseRelease $release = null,
    array $depositDispositions = []
): VehicleLease {
    $lease = new VehicleLease(array_merge([
        'vehicle_id' => $vehicleId,
        'currency' => 'LKR',
        'contract_type' => 'finance_lease',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'first_payment_date' => '2026-01-31',
        'installment_amount' => '10000.00',
        'payment_frequency' => 'monthly',
        'deposit_paid_amount' => '0.00',
        'status' => 'active',
        'financial_status' => 'active',
        'activated_at' => '2026-01-01 09:00:00',
    ], $overrides));
    $lease->id = $id;
    $lease->created_at = $overrides['created_at'] ?? '2026-01-01 08:00:00';
    $lease->setRelation('schedules', collect($schedules));
    $lease->setRelation('payments', collect($payments));
    $lease->setRelation('release', $release);
    $lease->setRelation('depositDispositions', collect($depositDispositions));

    return $lease;
}

function vehicleLeaseAccountingProjection(array $leases, string $start, string $end): array
{
    $method = new ReflectionMethod(VehicleLeaseAccountingService::class, 'buildSummary');

    return $method->invoke(
        new VehicleLeaseAccountingService,
        collect($leases),
        Carbon::parse($start)->startOfDay(),
        Carbon::parse($end)->endOfDay()
    );
}

it('separates current commitments from historical activity and excludes deposits from run rate', function () {
    Carbon::setTestNow('2026-08-15 12:00:00');

    $partialPayment = vehicleLeaseAccountingPayment('payment-partial', '4000.00', '2026-08-10');
    $settledPayment = vehicleLeaseAccountingPayment('payment-settled', '7000.00', '2026-08-12');
    $reversedPayment = vehicleLeaseAccountingPayment(
        'payment-reversed',
        '500.00',
        '2026-08-13',
        'reversed',
        '2026-08-14 09:00:00'
    );

    $active = vehicleLeaseAccountingLease(
        'lease-active',
        'vehicle-active',
        ['deposit_paid_amount' => '2500.00'],
        [
            vehicleLeaseAccountingSchedule('2026-07-31', '5000.00'),
            vehicleLeaseAccountingSchedule('2026-08-31', '10000.00', [
                vehicleLeaseAccountingAllocation('4000.00', $partialPayment),
                vehicleLeaseAccountingAllocation('500.00', $reversedPayment),
            ]),
            vehicleLeaseAccountingSchedule('2026-09-30', '10000.00'),
        ],
        [$partialPayment, $reversedPayment]
    );

    $settled = vehicleLeaseAccountingLease(
        'lease-settled',
        'vehicle-settled',
        [
            'status' => 'closure_pending',
            'financial_status' => 'settled',
            'installment_amount' => '99000.00',
            'deposit_paid_amount' => '1500.00',
        ],
        [
            vehicleLeaseAccountingSchedule('2026-08-20', '7000.00', [
                vehicleLeaseAccountingAllocation('7000.00', $settledPayment),
            ]),
        ],
        [$settledPayment]
    );

    $draft = vehicleLeaseAccountingLease(
        'lease-draft',
        'vehicle-draft',
        [
            'status' => 'draft',
            'financial_status' => 'pending',
            'activated_at' => null,
            'installment_amount' => '500000.00',
            'deposit_paid_amount' => '500000.00',
        ],
        [vehicleLeaseAccountingSchedule('2026-08-10', '500000.00')]
    );

    $summary = vehicleLeaseAccountingProjection(
        [$active, $settled, $draft],
        '2026-08-01',
        '2026-08-31'
    );

    expect($summary['by_currency']['LKR'])->toMatchArray([
        'monthly_run_rate' => 10000.0,
        'scheduled_in_period' => 17000.0,
        'allocated_in_period' => 11000.0,
        'remaining_due_in_period' => 6000.0,
        'cash_paid_in_period' => 11500.0,
        'payment_reversals_in_period' => 500.0,
        'overdue' => 5000.0,
        'total_outstanding' => 21000.0,
        'refundable_deposit_asset' => 4000.0,
        'pending_release_payable' => 0.0,
        'pending_release_receivable' => 0.0,
    ]);
    expect($summary['per_vehicle']['vehicle-active'])->toMatchArray([
        'current_lease_id' => 'lease-active',
        'currency' => 'LKR',
        'monthly_run_rate' => 10000.0,
        'outstanding' => 21000.0,
        'overdue' => 5000.0,
    ]);
    expect($summary['per_vehicle'])->not->toHaveKey('vehicle-settled')
        ->and($summary['per_vehicle'])->not->toHaveKey('vehicle-draft');
});

it('preserves dated release cash and refundable deposit dispositions', function () {
    $release = new VehicleLeaseRelease([
        'settlement_status' => 'settled',
        'deposit_credit' => '100.00',
        'net_settlement_amount' => '50.00',
        'settlement_direction' => 'payable_to_provider',
        'settlement_amount' => '50.00',
        'settled_at' => '2026-08-12 12:00:00',
    ]);
    $returned = new VehicleLeaseDepositDisposition([
        'disposition_type' => 'return_received',
        'amount' => '150.00',
        'transaction_date' => '2026-08-13',
        'payment_method' => 'bank_transfer',
        'reference' => 'DEP-RETURN-1',
        'status' => 'recorded',
    ]);
    $returned->id = 'deposit-return';
    $lease = vehicleLeaseAccountingLease(
        'lease-deposit',
        'vehicle-deposit',
        [
            'currency' => 'LKR',
            'status' => 'released',
            'financial_status' => 'settled',
            'deposit_paid_amount' => '500.00',
            'deposit_paid_date' => '2026-07-01',
            'down_payment' => '1000.00',
            'down_payment_paid_date' => '2026-08-02',
        ],
        [],
        [],
        $release,
        [$returned]
    );

    $summary = vehicleLeaseAccountingProjection([$lease], '2026-08-01', '2026-08-31');

    expect($summary['by_currency']['LKR'])->toMatchArray([
        'refundable_deposit_asset' => 250.0,
        'release_cash_paid_in_period' => 50.0,
        'release_cash_received_in_period' => 0.0,
        'down_payment_paid_in_period' => 1000.0,
        'refundable_deposit_cash_received_in_period' => 150.0,
    ]);
});

it('normalizes periodic instalments in minor units and caps run rate at outstanding', function () {
    $method = new ReflectionMethod(VehicleLeaseAccountingService::class, 'monthlyRunRateMinorUnits');
    $service = new VehicleLeaseAccountingService;
    $lease = vehicleLeaseAccountingLease('lease-quarterly', 'vehicle-quarterly', [
        'installment_amount' => '100.00',
        'payment_frequency' => 'quarterly',
    ]);

    expect($method->invoke($service, $lease, 10000))->toBe(3333)
        ->and($method->invoke($service, $lease, 2001))->toBe(2001);

    $lease->financial_status = 'settled';
    expect($method->invoke($service, $lease, 10000))->toBe(0);

    $lease->financial_status = 'active';
    $lease->status = 'closure_pending';
    expect($method->invoke($service, $lease, 10000))->toBe(0);
});

it('keeps release settlements and refundable deposit assets separated by currency', function () {
    Carbon::setTestNow('2026-08-15 12:00:00');

    $usdRelease = new VehicleLeaseRelease([
        'settlement_status' => 'pending',
        'deposit_credit' => '200.00',
        'net_settlement_amount' => '-125.55',
    ]);
    $eurRelease = new VehicleLeaseRelease([
        'settlement_status' => 'pending',
        'deposit_credit' => '0.00',
        'net_settlement_amount' => '75.25',
    ]);

    $usdLease = vehicleLeaseAccountingLease(
        'lease-usd',
        'vehicle-usd',
        [
            'currency' => 'usd',
            'status' => 'released',
            'deposit_paid_amount' => '500.00',
        ],
        [vehicleLeaseAccountingSchedule('2026-08-01', '50.00')],
        [],
        $usdRelease
    );
    $eurLease = vehicleLeaseAccountingLease(
        'lease-eur',
        'vehicle-eur',
        [
            'currency' => 'EUR',
            'status' => 'released',
            'deposit_paid_amount' => '0.00',
        ],
        [],
        [],
        $eurRelease
    );

    $summary = vehicleLeaseAccountingProjection(
        [$usdLease, $eurLease],
        '2026-08-01',
        '2026-08-31'
    );

    expect(array_keys($summary['by_currency']))->toBe(['EUR', 'USD'])
        ->and($summary['by_currency']['USD'])->toMatchArray([
            'monthly_run_rate' => 0.0,
            'scheduled_in_period' => 50.0,
            'remaining_due_in_period' => 0.0,
            'overdue' => 0.0,
            'total_outstanding' => 0.0,
            'refundable_deposit_asset' => 300.0,
            'pending_release_payable' => 0.0,
            'pending_release_receivable' => 125.55,
        ])
        ->and($summary['by_currency']['EUR'])->toMatchArray([
            'refundable_deposit_asset' => 0.0,
            'pending_release_payable' => 75.25,
            'pending_release_receivable' => 0.0,
        ])
        ->and($summary['per_vehicle'])->toBe([]);
});

it('rejects an inverted accounting period before querying leases', function () {
    expect(fn () => (new VehicleLeaseAccountingService)->portfolioSummary(
        '2026-08-31',
        '2026-08-01',
        []
    ))->toThrow(InvalidArgumentException::class);
});
