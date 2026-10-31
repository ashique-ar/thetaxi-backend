<?php

it('binds accounting delivery retries to the payout and exact acknowledgement facts', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionPayoutService.php'));
    $delivery = substr($service, strpos($service, 'public function recordAccountingDelivery('), strpos($service, 'private function checksum(') - strpos($service, 'public function recordAccountingDelivery('));
    $model = file_get_contents(app_path('Models/Sales/SalesCommissionAccountingDelivery.php'));
    $migration = file_get_contents(database_path('migrations/2026_10_30_000092_remove_commission_accounting_delivery_tenant_company.php'));
    $payoutModel = file_get_contents(app_path('Models/Sales/SalesCommissionPayout.php'));
    $statementMigration = file_get_contents(database_path('migrations/2026_10_30_000094_remove_commission_statement_tenant_company.php'));

    expect($delivery)
        ->toContain("where('idempotency_key', \$data['idempotency_key'])->lockForUpdate()->first()")
        ->toContain('$this->checksum($facts)')
        ->toContain("'payout_id' => (string) \$payout->id")
        ->toContain("\$payout->accounting_status === 'delivered'")
        ->toContain('sales.commission.accounting_delivery_recorded')
        ->not->toContain('lockActiveCompany', "'company_id' =>");
    expect($model)->not->toContain("'company_id'");
    expect($payoutModel)->not->toContain("'company_id'");
    expect($migration)->toContain("'sales_commission_accounting_deliveries'", "dropColumn('company_id')");
    expect($statementMigration)->toContain('normalizeLegacyDeliveryChecksums()', "dropColumn('company_id')", 'period_installation_unique');
});

it('recovers concurrent payout, reversal, and accounting-delivery idempotency collisions', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionPayoutService.php'));

    expect(substr_count($service, 'catch (QueryException $exception)'))->toBe(3)
        ->and($service)->toContain('if (! $duplicate) throw $exception;')
        ->toContain('hash_equals($duplicate->request_payload_checksum, $checksum)')
        ->toContain('hash_equals($duplicate->request_payload_checksum, $checksum), 409');
});

it('keeps payout and reversal execution installation-wide', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionPayoutService.php'));
    $pay = substr($service, strpos($service, 'public function pay('), strpos($service, 'public function reverse(') - strpos($service, 'public function pay('));
    $reverse = substr($service, strpos($service, 'public function reverse('), strpos($service, 'public function recordAccountingDelivery(') - strpos($service, 'public function reverse('));
    $delivery = substr($service, strpos($service, 'public function recordAccountingDelivery('), strpos($service, 'private function checksum(') - strpos($service, 'public function recordAccountingDelivery('));

    expect($pay)->not->toContain('company_id', 'lockActiveCompany')
        ->and($reverse)->not->toContain('company_id', 'lockActiveCompany')
        ->and($delivery)->not->toContain('lockActiveCompany');
});

it('removes payout company scope after checking installation-wide reference collisions', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_30_000093_remove_commission_payout_tenant_company.php'));

    expect($migration)->toContain("'sales_commission_payouts'", "'payment_reference'", "dropColumn('company_id')")
        ->and($migration)->toContain('havingRaw(\'COUNT(*) > 1\')');
});

it('requires reversal evidence and limits manual delivery reports to failures', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionStatementController.php'));
    $service = file_get_contents(app_path('Services/Sales/CommissionPayoutService.php'));

    expect($controller)
        ->toContain("'evidence_file_id' => ['required', 'uuid', 'exists:domain_evidence_files,id']")
        ->toContain("'status' => ['required', Rule::in(['failed'])]")
        ->toContain("'message' => ['required', 'string', 'max:2000']")
        ->and($service)
        ->toContain("abort_unless(! empty(\$data['evidence_file_id'])")
        ->toContain("->where('subject_type', 'commission_payout')->where('subject_id', \$original->id)");
});

it('adds nullable delivery replay evidence for legacy rows and supports rollback', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_03_000007_add_accounting_delivery_request_checksum.php'));

    expect($migration)
        ->toContain("Schema::table('sales_commission_accounting_deliveries'")
        ->toContain("char('request_payload_checksum', 64)->nullable()")
        ->toContain("dropColumn('request_payload_checksum')");
});

it('does not serialize payout replay checksums to the portal', function () {
    $payout = file_get_contents(app_path('Models/Sales/SalesCommissionPayout.php'));
    $delivery = file_get_contents(app_path('Models/Sales/SalesCommissionAccountingDelivery.php'));

    expect($payout)->toContain("protected \$hidden = ['payment_account_snapshot', 'request_payload_checksum'];")
        ->and($delivery)->toContain("protected \$hidden = ['request_payload_checksum'];");
});
