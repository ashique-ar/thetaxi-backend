<?php

it('persists receipt and financial-settlement payment idempotency in rollback-safe schema', function () {
    $migration = file_get_contents(database_path('migrations/2026_08_13_125000_add_idempotent_financial_settlement_payment_events.php'));

    expect($migration)
        ->toContain("Schema::table('booking_payment_receipts'")
        ->toContain("'booking_receipt_idempotency_unique'")
        ->toContain("Schema::create('financial_settlement_payment_events'")
        ->toContain("'financial_settlement_payment_idempotency_unique'")
        ->toContain("'request_payload_checksum'")
        ->toContain('Cannot remove persistent payment idempotency after payment evidence exists.');
});

it('serializes a settlement payment command and binds every allocated receipt to that event', function () {
    $service = file_get_contents(app_path('Services/FinancialAccountSettlementService.php'));

    expect($service)
        ->toContain("FinancialAccountSettlement::query()->lockForUpdate()")
        ->toContain("DB::table('financial_settlement_payment_events')")
        ->toContain('CanonicalJson::encode')
        ->toContain("hash_equals((string) \$existingEvent->request_payload_checksum, \$checksum)")
        ->toContain('financial-settlement:{$paymentEventId}:{$item->id}')
        ->toContain("BookingPaymentReceipt::query()->where('idempotency_key', \$receiptIdempotencyKey)")
        ->not->toContain("BookingPaymentReceipt::where('booking_id', \$item->booking_id)->latest('created_at')");
});

it('requires source currency, persistent command identity, and approved non-LKR conversion facts', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/FinancialSettlementController.php'));
    $ledger = file_get_contents(app_path('Services/BookingPaymentLedgerService.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($controller)
        ->toContain("'source_amount'=>'required|numeric|gt:0'")
        ->toContain("'source_currency'=>'required|string|size:3'")
        ->toContain("'fx_rate_to_lkr'=>'nullable|required_unless:source_currency,LKR|numeric|gt:0'")
        ->toContain("'idempotency_key'=>'required|uuid'")
        ->toContain('assertSettlementCollectionScope')
        ->toContain("'Settlement was not found in the current legal-entity scope.'")
        ->and($ledger)
        ->toContain('A persistent payment source identity is required for canonical receipts.')
        ->toContain('Legacy paid-state evidence must be reconciled through the controlled repair workflow')
        ->and($routes)
        ->toContain("'permission:sales.collections.verify'");
});

it('keeps every current successful booking collection writer behind the canonical ledger', function () {
    $payment = file_get_contents(app_path('Http/Controllers/Api/PaymentController.php'));
    $checkout = file_get_contents(app_path('Http/Controllers/CheckoutController.php'));
    $assignment = file_get_contents(app_path('Http/Controllers/Api/AssignmentController.php'));
    $driver = file_get_contents(app_path('Services/Driver/TripTrackingService.php'));
    $submissions = file_get_contents(app_path('Services/Sales/CollectionScheduleWorkflowService.php'));
    $settlements = file_get_contents(app_path('Services/FinancialAccountSettlementService.php'));

    expect($payment)->toContain('$this->paymentLedger->receive(')
        ->and($checkout)->toContain('$this->paymentLedger->receive(')
        ->and($assignment)->toContain('$this->paymentLedger->receive(')
        ->and($driver)->toContain('BookingPaymentLedgerService::class')->toContain('->receive($booking, [')
        ->and($submissions)->toContain('$this->ledger->receive(')
        ->and($settlements)->toContain('$this->ledger->receive(');
});
