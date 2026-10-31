<?php

it('converts target import replay checksums before dropping transfer job company scope', function (): void {
    $migration = file_get_contents(database_path('migrations/2026_10_31_000021_remove_transfer_job_tenant_company.php'));
    $service = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));

    expect($migration)
        ->toContain('normalizeTargetImportChecksums()')
        ->toContain("'company_id' => \$job->company_id")
        ->toContain("'request_payload_checksum' => \$installationChecksum")
        ->toContain('stored replay checksum does not match its preserved facts')
        ->toContain("dropColumn('company_id')")
        ->and($service)
        ->not->toContain("'company_id' => \$existing->company_id");
});
