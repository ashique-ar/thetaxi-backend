<?php

it('binds accounting delivery retries to the payout and exact acknowledgement facts', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionPayoutService.php'));

    expect($service)
        ->toContain("where('idempotency_key', \$data['idempotency_key'])->lockForUpdate()->first()")
        ->toContain('hash_equals($duplicate->request_payload_checksum, $checksum)')
        ->toContain("'payout_id' => (string) \$payout->id")
        ->toContain("'company_id' => (string) \$payout->company_id")
        ->toContain("\$payout->accounting_status === 'delivered'")
        ->toContain('sales.commission.accounting_delivery_recorded');
});

it('recovers concurrent payout, reversal, and accounting-delivery idempotency collisions', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionPayoutService.php'));

    expect(substr_count($service, 'catch (QueryException $exception)'))->toBe(3)
        ->and($service)->toContain('if (! $duplicate) throw $exception;')
        ->toContain('hash_equals($duplicate->request_payload_checksum, $checksum)')
        ->toContain('hash_equals($duplicate->request_payload_checksum, $checksum), 409');
});

it('locks an active company before payout, reversal, and delivery rows', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionPayoutService.php'));
    $pay = substr($service, strpos($service, 'public function pay('), strpos($service, 'public function reverse(') - strpos($service, 'public function pay('));
    $reverse = substr($service, strpos($service, 'public function reverse('), strpos($service, 'public function recordAccountingDelivery(') - strpos($service, 'public function reverse('));
    $delivery = substr($service, strpos($service, 'public function recordAccountingDelivery('), strpos($service, 'private function checksum(') - strpos($service, 'public function recordAccountingDelivery('));

    foreach ([$pay, $reverse, $delivery] as $mutation) {
        expect(strpos($mutation, '$this->lockActiveCompany('))->toBeLessThan(strpos($mutation, 'lockForUpdate()'));
    }
    expect($service)->toContain("where('is_active', true)", "whereNull('deleted_at')");
});

it('requires reversal evidence and keeps result-only delivery fields separate', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionStatementController.php'));
    $service = file_get_contents(app_path('Services/Sales/CommissionPayoutService.php'));

    expect($controller)
        ->toContain("'evidence_file_id' => ['required', 'uuid', 'exists:domain_evidence_files,id']")
        ->toContain("'external_reference' => ['nullable', 'required_if:status,accepted', 'prohibited_if:status,failed'")
        ->toContain("'message' => ['nullable', 'required_if:status,failed', 'prohibited_if:status,accepted'")
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
