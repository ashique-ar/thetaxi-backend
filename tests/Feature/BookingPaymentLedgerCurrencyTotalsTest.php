<?php

use App\Services\BookingPaymentLedgerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropIfExists('financial_account_settlements');
    Schema::dropIfExists('bookings');
    Schema::dropIfExists('corporates');

    Schema::create('corporates', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->timestamp('deleted_at')->nullable();
    });
    Schema::create('bookings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('booking_number');
        $table->boolean('is_corporate_booking')->default(true);
        $table->uuid('corporate_account_id');
        $table->string('currency', 3)->nullable();
        $table->string('status')->default('confirmed');
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    Schema::create('financial_account_settlements', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('settlement_number');
        $table->string('owner_type');
        $table->uuid('owner_id');
        $table->date('period_start')->nullable();
        $table->date('period_end')->nullable();
        $table->date('due_date')->nullable();
        $table->string('status');
        $table->string('invoice_number')->nullable();
        $table->decimal('charges_total', 20, 4)->default(0);
        $table->decimal('payments_total', 20, 4)->default(0);
        $table->decimal('outstanding_total', 20, 4)->default(0);
        $table->timestamp('settled_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
});

it('keeps account totals separate by currency and hides mixed-currency scalars', function () {
    $corporateId = '10000000-0000-4000-8000-000000000001';
    DB::table('corporates')->insert(['id' => $corporateId, 'name' => 'Acme']);
    DB::table('bookings')->insert([
        ['id' => '20000000-0000-4000-8000-000000000001', 'booking_number' => 'LKR-1', 'corporate_account_id' => $corporateId, 'currency' => 'LKR', 'created_at' => now()],
        ['id' => '20000000-0000-4000-8000-000000000002', 'booking_number' => 'USD-1', 'corporate_account_id' => $corporateId, 'currency' => 'USD', 'created_at' => now()->subMinute()],
    ]);

    $ledger = Mockery::mock(BookingPaymentLedgerService::class)->makePartial();
    $ledger->shouldReceive('summary')->twice()->andReturnUsing(fn ($booking) => [
        'contract_basis' => 'fixed_term',
        'total_amount' => $booking->currency === 'LKR' ? 1000 : 100,
        'generated_horizon_value' => null,
        'paid_amount' => $booking->currency === 'LKR' ? 300 : 25,
        'due_amount' => $booking->currency === 'LKR' ? 700 : 75,
        'settlement_due_date' => $booking->currency === 'LKR'
            ? today()->subDay()->toDateString()
            : today()->addDay()->toDateString(),
    ]);

    $account = $ledger->accountSummaryFor('corporate', $corporateId);
    $totals = collect($account['currency_totals'])->keyBy('currency');

    expect($account['total_charged'])->toBeNull()
        ->and($account['total_received'])->toBeNull()
        ->and($account['total_due'])->toBeNull()
        ->and($account['overdue_amount'])->toBeNull()
        ->and($totals['LKR']['total_charged'])->toBe(1000.0)
        ->and($totals['LKR']['total_received'])->toBe(300.0)
        ->and($totals['LKR']['overdue_amount'])->toBe(700.0)
        ->and($totals['USD']['total_charged'])->toBe(100.0)
        ->and($totals['USD']['total_due'])->toBe(75.0)
        ->and($totals['USD']['overdue_amount'])->toBe(0.0);
});

it('keeps legacy account totals available for a single currency', function () {
    $corporateId = '10000000-0000-4000-8000-000000000001';
    DB::table('corporates')->insert(['id' => $corporateId, 'name' => 'Acme']);
    DB::table('bookings')->insert([
        'id' => '20000000-0000-4000-8000-000000000001', 'booking_number' => 'KWD-1',
        'corporate_account_id' => $corporateId, 'currency' => 'KWD', 'created_at' => now(),
    ]);

    $ledger = Mockery::mock(BookingPaymentLedgerService::class)->makePartial();
    $ledger->shouldReceive('summary')->once()->andReturn([
        'contract_basis' => 'fixed_term', 'total_amount' => 12.345, 'generated_horizon_value' => null,
        'paid_amount' => 1.111, 'due_amount' => 11.234, 'settlement_due_date' => today()->addDay()->toDateString(),
    ]);

    $account = $ledger->accountSummaryFor('corporate', $corporateId);

    expect($account['currency'])->toBe('KWD')
        ->and($account['total_charged'])->toBe(12.345)
        ->and($account['total_received'])->toBe(1.111)
        ->and($account['total_due'])->toBe(11.234)
        ->and($account['currency_totals'])->toHaveCount(1);
});
