<?php

it('removes tenant company metadata from immutable receipt finality history', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_30_000082_remove_receipt_finality_event_tenant_company.php'));
    $service = file_get_contents(app_path('Services/BookingPaymentLedgerService.php'));
    $eventWrite = strpos($service, 'BookingPaymentReceiptFinalityEvent::create([');

    expect($migration)->toContain("'booking_payment_receipt_finality_events'", "dropColumn('company_id')")
        ->and($eventWrite)->not->toBeFalse()
        ->and(substr($service, $eventWrite, 500))->not->toContain("'company_id' => $receipt->company_id");
});
