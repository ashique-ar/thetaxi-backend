<?php

it('previews strict target CSV without storing files or writing targets', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPerformanceController.php'));
    $service = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)
        ->toContain("performance/targets/import-preview")
        ->toContain("performance/targets/import")
        ->toContain("permission:sales.performance.targets.manage")
        ->and($controller)
        ->toContain("'file' => ['required', 'file', 'max:2048', 'mimes:csv,txt']")
        ->toContain("'sales.performance.view-all', 'sales.performance.view-team'")
        ->and($service)
        ->toContain("'sales_code', 'new_sales_target_lkr', 'eligible_collections_target_lkr'")
        ->toContain("'write_performed' => false")
        ->toContain('hash_file(\'sha256\', $temporaryPath)')
        ->toContain("'profile_not_found_or_outside_scope'")
        ->toContain("'duplicate_sales_code'")
        ->toContain("'target_amount_required'");
});

it('retains exact missing versus zero semantics and only creates import drafts for accepted rows', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));

    expect($service)
        ->toContain("if (\$value === '') return null")
        ->toContain("\$whole === '' ? '0' : \$whole")
        ->toContain("if (\$row['action'] !== 'create_draft') continue")
        ->toContain("'source' => 'csv_import'")
        ->toContain("'status' => 'draft'")
        ->toContain("'import_row_number' => \$row['row_number']");
});

it('checksum-binds private import evidence with row errors idempotency locks and outbox audit', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));

    expect($service)
        ->toContain("Storage::disk('sales_private')->putFileAs")
        ->toContain("'job_type' => 'sales_target_csv'")
        ->toContain("'direction' => 'import'")
        ->toContain("'file_checksum' => \$preview['file_checksum']")
        ->toContain("domain_transfer_job_errors")
        ->toContain('hash_equals((string) $existing->request_payload_checksum, $requestChecksum)')
        ->toContain('outside your current scope')
        ->toContain('lockForUpdate()')
        ->toContain("'sales.performance.targets_imported_to_draft'")
        ->toContain("Storage::disk('sales_private')->delete(\$storedPath)");
});

it('adds restricted import lineage and refuses unsafe rollback after evidence exists', function () {
    $migration = file_get_contents(database_path('migrations/2026_08_13_149000_add_sales_target_import_lineage.php'));

    expect($migration)
        ->toContain("domain_transfer_job_idempotency_unique")
        ->toContain("->constrained('domain_transfer_jobs')->restrictOnDelete()")
        ->toContain("sales_target_import_job_row_unique")
        ->toContain("whereNotNull('copy_batch_id')")
        ->toContain('Rollback refused: export and reconcile immutable Sales target import evidence first.');
});
