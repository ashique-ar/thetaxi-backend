<?php

it('removes payment tenant columns only after preserving legacy repair replay evidence', function (): void {
    $migration = file_get_contents(database_path('migrations/2026_10_31_000014_remove_sales_payment_tenant_company.php'));
    $service = file_get_contents(app_path('Services/BookingPaymentLedgerService.php'));

    expect($migration)
        ->toContain("\$this->preserveRepairReplayEvidence()")
        ->toContain("\$this->dropTenantCompany('booking_payment_receipts')")
        ->toContain("\$this->dropTenantCompany('sales_booking_attributions')")
        ->toContain("'legacy_repair_after_checksum_v1' => \$legacyAfter")
        ->toContain("'legacy_repair_state_checksum_v2' => \$this->repairStateChecksum((string) \$receipt->booking_id)")
        ->toContain('no longer matches its immutable audit evidence; reconcile it first.')
        ->and($service)
        ->toContain('legacyRepairRequestMatchesReceipt(')
        ->toContain("'legacy_repair_checksum_version' => 2")
        ->toContain("'legacy_repair_state_checksum_v2' => $afterChecksum")
        ->not->toContain("'company_id' => \$legacyChecksumCompanyId", "'company_id' => \$companyId");
});
