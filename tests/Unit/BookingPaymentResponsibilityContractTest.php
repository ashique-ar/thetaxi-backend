<?php


it('projects the contractual payer separately from booking origin', function () {
    $service = file_get_contents(app_path('Services/BookingPaymentLedgerService.php'));

    expect($service)
        ->toContain("['customer', 'corporate', 'company']")
        ->toContain("'payment_responsibility' => \$payer['type']")
        ->toContain("'payer_type' => \$payer['type']")
        ->toContain("'payer_id' => \$payer['id']")
        ->toContain("'payer_name' => \$payer['name']")
        ->toContain("return ['type' => 'company', 'id' => null, 'name' => 'Company account']");
});

it('projects booking-linked financial artifacts without exposing document storage paths', function () {
    $service = file_get_contents(app_path('Services/BookingPaymentLedgerService.php'));
    $summary = Str::between($service, 'public function summary(', 'private function resolvePayer(');

    expect($summary)
        ->toContain("'financial_artifacts' => [")
        ->toContain("'settlement_item' => \$settlementItem")
        ->toContain("'allocations' => \$allocations->map")
        ->toContain("'adjustments' => \$adjustmentRows->map")
        ->toContain("'invoice' => \$settlementDocument")
        ->toContain("'download_available' => !empty(\$settlementDocument->pdf_path)")
        ->not->toContain("'pdf_path' =>")
        ->not->toContain("'pdf_disk' =>");
});

it('keeps financial lifecycle blockers backend-owned without blocking deferred arrangements', function () {
    $service = file_get_contents(app_path('Services/BookingLifecycleService.php'));
    $financialPolicy = Str::between(
        $service,
        'private function resolveFinancialLifecycleBlockingReasons(',
        'private function assertFinancialLifecycleReady('
    );

    expect($service)
        ->toContain("array_diff(\$actions, ['dispatch_vehicle', 'complete_booking'])")
        ->toContain("\$this->assertFinancialLifecycleReady(\$booking, 'dispatch')")
        ->toContain("\$this->assertFinancialLifecycleReady(\$booking, 'completion')")
        ->toContain('force completion may recover missing operational')
        ->toContain('resolveFinancialLifecycleBlockingReasons($bookingForStatus)');

    expect($financialPolicy)
        ->toContain("\$method === 'online'")
        ->toContain("->where('status', 'disputed')")
        ->toContain('Payment collection failed and must be resolved')
        ->not->toContain("'monthly_invoice'")
        ->not->toContain("'account_credit'")
        ->not->toContain("'cash_to_driver'");
});

it('keeps ledger evidence immutable and audits every financial workflow boundary', function () {
    $auditModel = file_get_contents(app_path('Models/Finance/FinancialAuditEvent.php'));
    $receiptModel = file_get_contents(app_path('Models/Booking/BookingPaymentReceipt.php'));
    $allocationModel = file_get_contents(app_path('Models/Finance/FinancialPaymentAllocation.php'));
    $adjustmentModel = file_get_contents(app_path('Models/Finance/FinancialAdjustment.php'));
    $refundModel = file_get_contents(app_path('Models/Booking/BookingDepositRefund.php'));
    $settlementService = file_get_contents(app_path('Services/FinancialAccountSettlementService.php'));
    $settlementController = file_get_contents(app_path('Http/Controllers/Api/FinancialSettlementController.php'));

    expect($auditModel)->toContain('Financial audit events are immutable')->toContain('cannot be deleted');
    expect($receiptModel)->toContain('IMMUTABLE_EVIDENCE_FIELDS')->toContain('Payment receipts cannot be deleted');
    expect($allocationModel)->toContain('Payment allocations are immutable')->toContain('cannot be deleted');
    expect($adjustmentModel)->toContain('Record a reversing adjustment instead');
    expect($refundModel)->toContain('Deposit refund evidence is immutable')->toContain('cannot be deleted');

    expect($settlementService)
        ->toContain("'payment_allocated'")
        ->toContain("'driver_cash_allocated'")
        ->toContain("'invoice_generated'")
        ->toContain("'invoice_generation_failed'")
        ->toContain("'invoice_sent'")
        ->toContain("'invoice_delivery_failed'")
        ->toContain("'driver_cash_disputed'")
        ->toContain("'driver_cash_dispute_resolved'")
        ->toContain("'marked_overdue'")
        ->toContain("FinancialSettlementItem::where('settlement_id', \$subjectId)->pluck('booking_id')");

    expect($settlementController)
        ->toContain('markOverdueSettlements(Auth::id())')
        ->toContain('disputeDriverCashReceipt(')
        ->toContain('resolveDriverCashReceiptDispute(');
});

it('reconciles driver cash custody without counting handoffs as new booking revenue', function () {
    $ledger = file_get_contents(app_path('Services/BookingPaymentLedgerService.php'));
    $driverSettlement = file_get_contents(app_path('Services/DriverHireSettlementService.php'));
    $accountSettlement = file_get_contents(app_path('Services/FinancialAccountSettlementService.php'));

    expect($ledger)
        ->toContain("'cash_custody' => [")
        ->toContain("'driver_collected' => \$driverCashCollected")
        ->toContain("'handed_over_to_company' => \$driverCashHandedOver")
        ->toContain("'still_held_by_driver' => max(0")
        ->not->toContain("\$paid + \$driverCashHandedOver");

    expect($driverSettlement)
        ->toContain('function reconcileCashHandoff(')
        ->toContain("whereNotIn('status', ['paid', 'recovered', 'rejected'])")
        ->toContain("['accounts_finalized', 'recovery_pending']");

    expect($accountSettlement)
        ->toContain('$this->driverSettlements->reconcileCashHandoff(')
        ->toContain("\$settlement->items()->pluck('booking_id')");
});
