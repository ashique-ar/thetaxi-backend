<?php

it('authorizes assignment payment collection by its Sales collection owner instead of booking company', function (): void {
    $controller = file_get_contents(app_path('Http/Controllers/Api/AssignmentController.php'));
    $method = substr($controller, strpos($controller, 'public function receivePayment('), strpos($controller, 'public function addPaymentScheduleItem(') - strpos($controller, 'public function receivePayment('));

    expect($method)
        ->toContain("first(['collection_sales_profile_id'])")
        ->toContain("'sales.collections.view-team'")
        ->toContain('assertProfile(')
        ->not->toContain("first(['company_id'])", 'assertCompany(', "DB::table('companies')");
});

it('holds the booking and current attribution scope through collection schedule commands', function (): void {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CollectionScheduleWorkflowController.php'));

    expect(substr_count($controller, 'withinBookingManagementScope($request, $booking'))
        ->toBe(4)
        ->and($controller)
        ->toContain("Booking::query()->whereKey(\$booking->id)->lockForUpdate()->firstOrFail()")
        ->toContain("SalesBookingAttribution::query()->where('booking_id', \$booking->id)->lockForUpdate()->firstOrFail()")
        ->toContain("'sales.collections.view-team'");
});

it('holds the booking and attribution scope through payment and commercial adjustment commands', function (): void {
    $paymentAdjustments = file_get_contents(app_path('Http/Controllers/Api/Sales/BookingPaymentAdjustmentController.php'));
    $attribution = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesBookingAttributionController.php'));

    expect(substr_count($paymentAdjustments, 'withinBookingScope($request, $booking'))
        ->toBe(4)
        ->and($paymentAdjustments)
        ->toContain("Booking::query()->whereKey(\$booking->id)->lockForUpdate()->firstOrFail()")
        ->toContain("SalesBookingAttribution::query()->where('booking_id', \$booking->id)->lockForUpdate()->firstOrFail()")
        ->and(substr_count($attribution, 'withinCommercialAdjustmentScope($request, $attribution'))
        ->toBe(2)
        ->and($attribution)
        ->toContain('Booking::query()->whereKey($candidate->booking_id)->lockForUpdate()->firstOrFail()')
        ->toContain('SalesBookingAttribution::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail()');
});

it('keeps collection schedules tied to their booking without tenant ownership checks', function (): void {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CollectionScheduleWorkflowController.php'));

    expect($controller)
        ->toContain('private function assertCollectionPageReferences($rows)')
        ->toContain('(string) $schedule->booking_id === (string) $row->booking_id')
        ->not->toContain('SalesCollectionCompanyIntegrity', 'assertMany(', 'assertConsistent(');
});

it('scopes payment-ledger reconciliation by Sales access without a company selector', function (): void {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/PaymentLedgerReconciliationController.php'));
    $ledger = file_get_contents(app_path('Services/BookingPaymentLedgerService.php'));

    expect($controller)
        ->not->toContain("'company_id' => ['nullable', 'uuid', 'exists:companies,id']", 'resolveCompanyId', 'assertCompany', 'CompanyIdScope', 'company_state')
        ->toContain("'booking_number' => ['nullable', 'string', 'max:80']")
        ->toContain("assertAccess(\$request->user(), 'sales.collections.view-all')")
        ->toContain("->whereHas('salesAttribution'")
        ->toContain("'write_performed' => false")
        ->toContain("'has_components' => (bool) \$receipt->has_components")
        ->not->toContain("'booking_id' => ['nullable', 'uuid'")
        ->and($ledger)
        ->toContain('repairLegacyPaidBooking(Booking $booking, array $data, string $actorUserId)')
        ->not->toContain('sameCompanyId', 'authorizedCompanyId', 'DB::table(\'companies\')');
});

it('keeps every payment-ledger reconciliation endpoint free of company selectors', function (): void {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/PaymentLedgerReconciliationController.php'));

    foreach ([
        'public function preview(',
        'public function receiptComponentOptions(',
        'public function legacyReceiptBookingOptions(',
        'public function legacyReceiptRepairHistory(',
        'public function repairLegacyBooking(',
        'public function repairComponents(',
    ] as $marker) {
        $start = strpos($controller, $marker);
        $end = strpos($controller, "\n    public function ", $start + 1);
        $method = substr($controller, $start, $end === false ? null : $end - $start);

        expect($method)->not->toContain("'company_id' => ['nullable', 'uuid', 'exists:companies,id']", 'companyId', 'resolveCompanyId', 'assertCompany', 'CompanyIdScope')
            ->toContain("assertAccess(\$request->user(), 'sales.collections.view-all')");
    }
});

it('binds receipt component repair to restricted evidence and audited idempotent replay', function (): void {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/PaymentLedgerReconciliationController.php'));
    $evidence = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesEvidenceController.php'));
    $migration = file_get_contents(database_path('migrations/2026_10_03_000004_add_receipt_component_repair_evidence.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($controller)
        ->toContain('function receiptComponentOptions(Request $request)')
        ->toContain("assertAccess(\$request->user(), 'sales.collections.view-all')")
        ->not->toContain('attribution.company_id', 'CompanyIdScope', 'resolveCompanyId')
        ->toContain('function componentRepairChecksum(BookingPaymentReceiptComponent $component)')
        ->toContain("'evidence_type', 'legacy_payment_component_repair'")
        ->toContain("'classification', 'restricted'")
        ->toContain('repair_request_checksum')
        ->toContain('repair_before_checksum')
        ->toContain('Original component repair evidence is missing or inconsistent')
        ->toContain("'event_type' => 'sales.payment.component.repaired'")
        ->toContain("'correlation_id' => \$data['idempotency_key']")
        ->and($evidence)
        ->toContain("'sales.payment-finality.transition', 'sales.payment-ledger.reconcile'")
        ->and($migration)
        ->toContain('receipt_component_repair_key_unique')
        ->toContain('repair_evidence_file_id')
        ->toContain('Rollback refused: export and reconcile receipt component repair evidence first.')
        ->and($routes)
        ->toContain("'payment-ledger/receipt-component-options'")
        ->toContain('permission:sales.payment-ledger.reconcile');
});

it('binds legacy paid receipt repair to restricted booking evidence and checksum verified replay', function (): void {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/PaymentLedgerReconciliationController.php'));
    $ledger = file_get_contents(app_path('Services/BookingPaymentLedgerService.php'));
    $receipt = file_get_contents(app_path('Models/Booking/BookingPaymentReceipt.php'));
    $component = file_get_contents(app_path('Models/Booking/BookingPaymentReceiptComponent.php'));
    $migration = file_get_contents(database_path('migrations/2026_10_03_000005_add_legacy_receipt_repair_evidence.php'));
    $trace = file_get_contents(app_path('Services/BookingObservabilityService.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($controller)
        ->toContain('function legacyReceiptBookingOptions(Request $request)')
        ->toContain('function legacyReceiptRepairHistory(Request $request)')
        ->toContain("where('event.domain', 'sales')")
        ->not->toContain('event.company_id', 'matching_event.company_id', 'attribution.company_id', 'CompanyIdScope')
        ->toContain("whereColumn('event.booking_id', 'booking.id')")
        ->toContain("'audit_status' => \$auditValid && \$evidenceValid ? 'consistent' : 'held'")
        ->toContain("'reason' => ['required', 'string', 'min:10', 'max:2000']")
        ->toContain("'idempotency_key' => ['required', 'uuid']")
        ->and($ledger)
        ->toContain("'evidence_type', 'legacy_paid_receipt_repair'")
        ->toContain("'classification', 'restricted'")
        ->toContain("abort_unless(\$sourceCurrency === 'LKR'")
        ->toContain("'legacy_repair_request_checksum' => \$requestChecksum")
        ->toContain("'legacy_repair_before_checksum' => \$beforeChecksum")
        ->toContain("'after_checksum' => \$afterChecksum")
        ->toContain("'event_type' => 'legacy_payment_receipt_repaired'")
        ->toContain("'repair_reason' => trim(\$data['reason'])")
        ->toContain('legacyRepairStateChecksum')
        ->toContain('replayed\' => true')
        ->and($receipt)
        ->toContain('legacy_repair_evidence_file_id')
        ->toContain('legacy_repair_reason')
        ->toContain("protected \$hidden = [")
        ->and($component)
        ->toContain("protected \$hidden = [")
        ->toContain('repair_evidence_file_id')
        ->toContain('repair_reason')
        ->and($migration)
        ->toContain('legacy_repair_request_checksum')
        ->toContain('legacy_repair_before_checksum')
        ->toContain('Rollback refused: export and reconcile legacy receipt repair evidence first.')
        ->and($trace)
        ->not->toContain("'repair_reason'")
        ->and($routes)
        ->toContain("'payment-ledger/legacy-receipt-booking-options'")
        ->toContain("'payment-ledger/legacy-receipt-repair-history'")
        ->toContain("'bookings/{booking}/payment-ledger/repair-legacy'");
});
