<?php

uses(Tests\TestCase::class);

it('keeps corporate finance routes company scoped and payment permission protected', function () {
    $routes=file_get_contents(base_path('routes/api.php'));
    $controller=file_get_contents(app_path('Http/Controllers/Api/Corporate/CorporateFinanceController.php'));
    expect($routes)->toContain("Route::get('finance/account'")->toContain("Route::get('finance/settlements/{settlement}'")->toContain("Route::get('finance/settlements/{settlement}/invoice'")->toContain("Route::get('finance/settlements/{settlement}/statement'")
        ->and($controller)->toContain("middleware('permission:view_payments')")->toContain("->where('owner_id', \$request->corporate_id)")->not->toContain('return $row->toArray()');
});

it('freezes billing terms trip sources and statement totals behind an idempotency key', function () {
    $migration=file_get_contents(database_path('migrations/2026_08_31_120000_add_corporate_monthly_billing.php'));
    $service=file_get_contents(app_path('Services/CorporateMonthlyBillingService.php'));
    expect($migration)->toContain("Schema::create('corporate_billing_terms'")->toContain("'generation_key'")->toContain("'billing_terms_snapshot'")->toContain("'statement_snapshot'")->toContain("'booking_item_ids'")->toContain("'source_snapshot'")
        ->and($service)->toContain("lockForUpdate()")->toContain("where('generation_key'")->toContain("pricing_snapshot_hash")->toContain("corporate_monthly_billing_generated")->toContain("corporate_statement_sent");
});

it('reuses canonical settlement issue allocation adjustment and dispute owners', function () {
    $billing=file_get_contents(app_path('Services/CorporateMonthlyBillingService.php'));
    $settlement=file_get_contents(app_path('Services/FinancialAccountSettlementService.php'));
    expect($billing)->toContain('$this->settlements->recalculate(')->toContain('$this->settlements->issue(')
        ->and($settlement)->toContain('public function receivePayment(')->toContain('public function adjust(')->toContain('public function dispute(')->toContain('public function resolveDispute(');
});
