<?php

use App\Services\CorporateManagementAnalyticsService;
use App\Http\Controllers\Api\Corporate\CorporateManagementReportController;
use App\Http\Requests\Corporate\CorporateReportFiltersRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

it('omits monetary fields from management data without the selected context payment permission', function () {
    Schema::create('bookings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('corporate_account_id')->nullable();
        $table->softDeletes();
    });
    Schema::create('booking_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->softDeletes();
    });

    $payload = app(CorporateManagementAnalyticsService::class)->report(
        '00000000-0000-4000-8000-000000000000',
        [],
        false
    );

    expect($payload['financial_metrics_visible'])->toBeFalse()
        ->and($payload['operational'])->not->toHaveKeys(['estimated_booking_value', 'finalized_charges'])
        ->and($payload)->not->toHaveKeys(['financial', 'financial_period']);

});

it('returns billing-period totals in the statement currency instead of travel-period balances', function () {
    Schema::create('bookings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('corporate_account_id')->nullable();
        $table->softDeletes();
    });
    Schema::create('booking_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->softDeletes();
    });
    Schema::create('financial_account_settlements', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('owner_type');
        $table->uuid('owner_id');
        $table->string('status');
        $table->date('period_start');
        $table->date('period_end');
        $table->string('settlement_number');
        $table->string('invoice_number')->nullable();
        $table->json('billing_terms_snapshot')->nullable();
        $table->decimal('charges_total', 14, 2);
        $table->decimal('payments_total', 14, 2);
        $table->decimal('refunds_total', 14, 2);
        $table->decimal('adjustments_total', 14, 2);
        $table->decimal('outstanding_total', 14, 2);
        $table->softDeletes();
        $table->timestamps();
    });
    Schema::create('financial_settlement_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('settlement_id');
        $table->uuid('booking_id');
        $table->json('booking_item_ids')->nullable();
        $table->softDeletes();
    });

    $settlementId = '00000000-0000-4000-8000-000000000010';
    DB::table('financial_account_settlements')->insert([
        'id' => $settlementId,
        'owner_type' => 'corporate',
        'owner_id' => '00000000-0000-4000-8000-000000000001',
        'status' => 'issued',
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'settlement_number' => 'CB-202609-TEST',
        'invoice_number' => 'INV-202609-TEST',
        'billing_terms_snapshot' => json_encode(['currency' => 'USD']),
        'charges_total' => 120.50,
        'payments_total' => 20.50,
        'refunds_total' => 0,
        'adjustments_total' => 0,
        'outstanding_total' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = app(CorporateManagementAnalyticsService::class)->report(
        '00000000-0000-4000-8000-000000000001',
        ['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'billing_period_id' => $settlementId],
        true
    );

    expect($payload['financial_period']['basis'])->toBe('billing_period')
        ->and($payload['financial_period']['summary']['currency'])->toBe('USD')
        ->and($payload['financial_period']['summary']['charges_total'])->toBe(120.5)
        ->and($payload['financial_period']['summary']['outstanding_total'])->toBe(100.0)
        ->and($payload)->not->toHaveKey('financial');
});

it('validates report filters without recursively restarting its validator', function () {
    $request = new App\Http\Requests\Corporate\CorporateReportFiltersRequest;
    $validator = Validator::make([], $request->rules());
    $validator->after($request->after());
    $request->setValidator($validator);

    expect($request->validated())->toBe([]);
});

it('exports monthly currency trends and selected billing totals in the management CSV', function () {
    $payload = [
        'financial_metrics_visible' => true,
        'financial_period' => ['summary' => [
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'settlement_number' => 'CB-SEP',
            'invoice_number' => 'INV-SEP', 'currency' => 'KWD', 'charges_total' => 1.234,
            'payments_total' => 0, 'refunds_total' => 0, 'adjustments_total' => 0, 'outstanding_total' => 1.234,
        ]],
        'monthly_trends' => [[
            'month' => '2026-09', 'currency' => 'KWD', 'bookings' => 1, 'trips' => 2,
            'estimated_value' => 1.234, 'finalized_charges' => 1.234,
        ]],
        'dimensions' => [],
    ];
    $analytics = Mockery::mock(CorporateManagementAnalyticsService::class);
    $analytics->shouldReceive('report')->once()->with('corp-id', [], false)->andReturn($payload);
    $request = new CorporateReportFiltersRequest;
    $request->merge(['corporate_id' => 'corp-id']);
    $validator = Validator::make([], $request->rules());
    $validator->after($request->after());
    $request->setValidator($validator);
    $response = (new CorporateManagementReportController($analytics))->export($request, 'csv');

    ob_start();
    $response->getCallback()();
    $csv = ob_get_clean();

    expect($csv)->toContain('Selected billing period', 'Monthly travel activity', '2026-09,KWD,1,2,1.234,1.234');
});
