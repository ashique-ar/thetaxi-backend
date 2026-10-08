<?php

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

it('fails closed when collection history has missing or conflicting company ownership', function (): void {
    $integrity = file_get_contents(app_path('Services/Sales/SalesCollectionCompanyIntegrity.php'));
    $paymentLedger = file_get_contents(app_path('Services/BookingPaymentLedgerService.php'));
    $scheduleController = file_get_contents(app_path('Http/Controllers/Api/Sales/CollectionScheduleWorkflowController.php'));
    $paymentAdjustmentController = file_get_contents(app_path('Http/Controllers/Api/Sales/BookingPaymentAdjustmentController.php'));
    $attributionController = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesBookingAttributionController.php'));
    $rollingScheduleService = file_get_contents(app_path('Services/Sales/RollingPaymentScheduleService.php'));

    expect($integrity)
        ->toContain("'booking_payment_schedules'")
        ->toContain("'booking_payment_schedule_revisions'")
        ->toContain("'booking_payment_schedule_rules'")
        ->toContain("'booking_collection_work_items'")
        ->toContain("'booking_collection_submissions'")
        ->toContain("'booking_payment_receipts'")
        ->toContain("'booking_payment_receipt_finality_events'")
        ->toContain("'booking_collection_reminder_deliveries'")
        ->toContain("'booking_payment_adjustments'")
        ->toContain("'booking_commercial_value_adjustments'")
        ->toContain("'booking_collection_work_items' => 'assigned_sales_profile_id'")
        ->toContain("'booking_collection_submissions' => 'submitted_by_sales_profile_id'")
        ->toContain("whereNull('company_id')->orWhere('company_id', '!=', \$companyId)")
        ->and($scheduleController)->toContain('companyIntegrity->assertConsistent')
        ->and($paymentAdjustmentController)->toContain('companyIntegrity->assertConsistent')
        ->and($attributionController)->toContain('collectionCompanyIntegrity->assertConsistent')
        ->and($rollingScheduleService)->toContain('companyIntegrity->assertConsistent')
        ->and(file_get_contents(app_path('Services/Sales/CollectionScheduleWorkflowService.php')))
        ->toContain('companyIntegrity->assertConsistent')
        ->and($paymentLedger)
        ->toContain('collectionCompanyIntegrity->assertConsistent($booking, (string) $attribution->company_id)');
});

it('exposes a read-only collection company mismatch report with readable references', function (): void {
    $integrity = file_get_contents(app_path('Services/Sales/SalesCollectionCompanyIntegrity.php'));
    $command = file_get_contents(app_path('Console/Commands/AuditSalesCollectionCompanyOwnership.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesCollectionCompanyRepairController.php'));
    $repairService = file_get_contents(app_path('Services/Sales/SalesCollectionCompanyRepairService.php'));
    $finalityMigration = file_get_contents(database_path('migrations/2026_08_12_142000_create_receipt_finality_workflow.php'));
    $reminderMigration = file_get_contents(database_path('migrations/2026_08_13_133000_create_booking_collection_reminder_deliveries.php'));

    expect($integrity)
        ->toContain('function mismatchReport(?string $bookingNumber = null, ?string $companyId = null)')
        ->toContain("'booking_number' => \$record->booking_number")
        ->toContain("'expected_company' => \$record->expected_company")
        ->toContain("'recorded_company' => \$record->recorded_company")
        ->toContain("'reference_field' => \$column")
        ->toContain("'recorded_profile' => ! \$record->expected_company_id")
        ->toContain('Sales Profile outside booking entity')
        ->toContain("? 'Another legal entity'")
        ->toContain('function mismatchSummary(array $items)')
        ->toContain("'historical_company_evidence_mismatch'")
        ->toContain("'repairable' => ! \$immutableEvidence")
        ->toContain('function assertNoImmutableEvidenceMismatch(Booking $booking, string $companyId)')
        ->toContain("'by_issue' => []")
        ->toContain("'by_record_type' => []")
        ->and($command)
        ->toContain("sales:audit-collection-companies {--booking-number=")
        ->toContain("'read_only' => true")
        ->toContain("'repair_performed' => false")
        ->toContain("'summary' => \$integrity->mismatchSummary(\$items)")
        ->toContain('mismatchReport($bookingNumber');
    expect($controller)->toContain("'summary' => \$this->integrity->mismatchSummary(\$items)");
    expect($finalityMigration)->toContain("Schema::create('booking_payment_receipt_finality_events'")
        ->toContain("foreignUuid('company_id')")
        ->and($reminderMigration)->toContain("Schema::create('booking_collection_reminder_deliveries'")
        ->toContain("foreignUuid('company_id')")
        ->and($repairService)->toContain('assertNoImmutableEvidenceMismatch($booking, $attribution->company_id)')
        ->toContain('NON_REPAIRABLE_TABLES');
});

it('requires a fresh preview, booking-bound evidence and an audit record for company repairs', function (): void {
    $repair = file_get_contents(app_path('Services/Sales/SalesCollectionCompanyRepairService.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesCollectionCompanyRepairController.php'));
    $migration = file_get_contents(database_path('migrations/2026_10_03_000002_create_sales_collection_company_repairs.php'));
    $evidence = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesEvidenceController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($repair)
        ->toContain('lockForUpdate()')
        ->toContain("where('subject_type', 'booking')->where('subject_id', \$booking->id)")
        ->toContain("'event_type' => 'sales.collection.company_repaired'")
        ->toContain("hash_equals(\$snapshot['checksum'], \$data['preview_checksum'])")
        ->toContain('assertConsistent($booking, $attribution->company_id)')
        ->toContain('assertNoImmutableEvidenceMismatch($booking, $attribution->company_id)')
        ->toContain("if (\$profileCompany !== \$attribution->company_id) \$blocked = true;")
        ->toContain("'source_key_hash' => hash('sha256', \$row['table'].':'.\$row['id'])")
        ->toContain('function history(string $companyId)')
        ->toContain("->on('evidence.company_id', '=', 'repair.company_id')")
        ->toContain("->on('rollback.company_id', '=', 'repair.company_id')")
        ->toContain("->on('rollback_evidence.company_id', '=', 'rollback.company_id')")
        ->toContain("THEN 'Another legal entity'")
        ->toContain("COALESCE(after_company.name, 'Unavailable legal entity record')")
        ->toContain('rollback_baseline_available')
        ->and($controller)
        ->toContain("'sales.collections.view-all'")
        ->toContain('function companyOptions(Request $request)')
        ->and($migration)
        ->toContain('sales_collection_company_repairs')
        ->toContain('source_key_hash')
        ->toContain('cannot be rolled back')
        ->and($evidence)
        ->toContain("if (! \$request->user()->can('sales.payment-ledger.reconcile'))")
        ->and($routes)
        ->toContain("'collection-company-repairs', [SalesCollectionCompanyRepairController::class, 'apply']")
        ->toContain("'collection-company-repairs', [SalesCollectionCompanyRepairController::class, 'history']")
        ->toContain("permission:sales.payment-ledger.reconcile");
});

it('rolls back only unchanged company repairs with fresh evidence and idempotent audit history', function (): void {
    $repair = file_get_contents(app_path('Services/Sales/SalesCollectionCompanyRepairService.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesCollectionCompanyRepairController.php'));
    $migration = file_get_contents(database_path('migrations/2026_10_03_000003_add_sales_collection_company_repair_rollback.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($repair)
        ->toContain('public function preview(string $bookingNumber, string $authorizedCompanyId)')
        ->toContain('public function apply(string $bookingNumber, array $data, string $actorUserId, string $authorizedCompanyId)')
        ->toContain('function rollbackPreview(string $bookingNumber, string $authorizedCompanyId)')
        ->toContain('function rollback(string $bookingNumber, array $data, string $actorUserId, string $authorizedCompanyId)')
        ->toContain("whereNotExists(fn (\$scope) => \$scope->selectRaw('1')")
        ->toContain("->whereNull('repair.after_checksum')")
        ->toContain('Historical collection repair checksum is missing; approved historical disposition is required before rollback.')
        ->toContain("'after_checksum' => \$afterChecksum")
        ->toContain("'event_type' => 'sales.collection.company_repair_rolled_back'")
        ->toContain('function assertAuditEvidence(')
        ->toContain('Original transaction audit evidence is missing or inconsistent')
        ->toContain('lockForUpdate()')
        ->toContain('hash_equals($authorizedCompanyId, (string) $attribution->company_id)')
        ->toContain("->where('repair.booking_id', \$booking->id)")
        ->toContain("->where('repair.company_id', \$attribution->company_id)")
        ->toContain('The booking legal entity changed; refresh the authorized')
        ->toContain("where('subject_type', 'booking')->where('subject_id', \$booking->id)")
        ->toContain('sales_collection_company_repair_rollback_items')
        ->and($controller)
        ->toContain('private function authorizeBooking(Request $request, string $bookingNumber): string')
        ->toContain('return (string) $attribution->company_id;')
        ->toContain('function rollbackPreview(Request $request)')
        ->toContain('function rollback(Request $request)')
        ->and($migration)
        ->toContain('sales_collection_company_repair_rollbacks')
        ->toContain('sales_collection_company_repair_rollback_once_unique')
        ->toContain('request_record_count')
        ->toContain('record_count')
        ->toContain('repair and rollback evidence is retained')
        ->and($routes)
        ->toContain("'collection-company-repairs/rollback-preview'")
        ->toContain("'collection-company-repairs/rollback'")
        ->toContain('permission:sales.payment-ledger.reconcile');
});

it('replays company repairs only when all repair rows match the booking and legal entity', function () {
    $repair = file_get_contents(app_path('Services/Sales/SalesCollectionCompanyRepairService.php'));

    expect($repair)
        ->toContain('(string) $duplicate->booking_id === (string) $booking->id')
        ->toContain('(string) $duplicate->company_id === (string) $attribution->company_id')
        ->toContain('$repairRows->every(fn ($row) => (string) $row->booking_id === (string) $booking->id')
        ->toContain("->where('repair.booking_id', \$booking->id)")
        ->toContain("->where('repair.company_id', \$attribution->company_id)");
});

it('scopes payment-ledger reconciliation to an authorized company without returning managed IDs', function (): void {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/PaymentLedgerReconciliationController.php'));
    $ledger = file_get_contents(app_path('Services/BookingPaymentLedgerService.php'));

    expect($controller)
        ->toContain("'company_id' => ['nullable', 'uuid', 'exists:companies,id']")
        ->toContain('->resolveCompanyId($data[\'company_id\'] ?? null)')
        ->toContain("'booking_number' => ['nullable', 'string', 'max:80']")
        ->toContain("assertCompany(\$request->user(), \$data['company_id'], 'sales.collections.view-all')")
        ->toContain("->whereHas('salesAttribution'")
        ->toContain("'write_performed' => false")
        ->toContain('company_state')
        ->toContain("'has_components' => (bool) \$receipt->has_components")
        ->toContain("hash_equals(\$data['company_id'], (string) \$attribution->company_id)")
        ->not->toContain("'booking_id' => ['nullable', 'uuid'")
        ->and($ledger)
        ->toContain('repairLegacyPaidBooking(Booking $booking, array $data, string $actorUserId, string $authorizedCompanyId)')
        ->toContain('assertConsistent($booking, (string) $attribution->company_id)');
});

it('resolves omitted reconciliation and legacy receipt repair company IDs to the active default', function (): void {
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

        expect($method)->toContain("'company_id' => ['nullable', 'uuid', 'exists:companies,id']")
            ->toContain('->resolveCompanyId($data[\'company_id\'] ?? null)')
            ->toContain("assertCompany(\$request->user(), \$data['company_id'], 'sales.collections.view-all')");
    }
});

it('binds receipt component repair to company scope, restricted evidence, and audited idempotent replay', function (): void {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/PaymentLedgerReconciliationController.php'));
    $evidence = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesEvidenceController.php'));
    $migration = file_get_contents(database_path('migrations/2026_10_03_000004_add_receipt_component_repair_evidence.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($controller)
        ->toContain('function receiptComponentOptions(Request $request)')
        ->toContain("where('attribution.company_id', \$data['company_id'])")
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
        ->toContain("where('event.company_id', \$data['company_id'])")
        ->toContain("where('matching_event.company_id', \$data['company_id'])")
        ->toContain("where('attribution.company_id', \$data['company_id'])")
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
