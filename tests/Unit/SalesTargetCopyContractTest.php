<?php

it('keeps target copy preview write-free and restricted to the central Sales Profile scope', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPerformanceController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $service = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));

    expect($routes)
        ->toContain("performance/targets/copy-preview")
        ->toContain("performance/targets/copy")
        ->toContain("permission:sales.performance.targets.manage")
        ->and($controller)
        ->toContain('assertTargetProfilesScope')
        ->toContain("'sales.performance.view-all', 'sales.performance.view-team'")
        ->toContain("'profile_ids.*' => ['required', 'uuid', 'distinct', 'exists:sales_profiles,id']")
        ->and($service)
        ->toContain("'write_performed' => false")
        ->toContain("'source_not_configured'")
        ->toContain("'source_approved_ambiguous'")
        ->toContain("'profile_not_effective'");
});

it('copies only one approved source while preserving missing and explicit zero target values', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));

    expect($service)
        ->toContain("->where('status', 'approved')->get()")
        ->toContain("'new_sales_target_lkr' => \$source?->new_sales_target_lkr")
        ->toContain("'eligible_collections_target_lkr' => \$source?->eligible_collections_target_lkr")
        ->toContain("if (\$row['action'] !== 'create_draft') continue")
        ->toContain("'status' => 'draft'")
        ->toContain("'copied_from_target_id' => \$row['source_target_id']");
});

it('checksum-binds an idempotent copy batch under row locks and retains maker-checker approval', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));

    expect($service)
        ->toContain('CanonicalJson::encode($snapshot)')
        ->toContain("where('idempotency_key', \$idempotencyKey)")
        ->toContain('hash_equals($existing->request_payload_checksum, $requestChecksum)')
        ->toContain('lockForUpdate()')
        ->toContain('hash_equals($preview[\'preview_checksum\'], $previewChecksum)')
        ->toContain("'copy_batch_id' => \$batch->id")
        ->toContain("abort_if(\$locked->prepared_by === \$actorUserId")
        ->toContain("'sales.performance.targets_copied_to_draft'");
});

it('uses additive restricted lineage and refuses unsafe rollback after target-copy evidence exists', function () {
    $migration = file_get_contents(database_path('migrations/2026_08_13_148000_create_sales_target_copy_batches.php'));
    $model = file_get_contents(app_path('Models/Sales/SalesTargetCopyBatch.php'));

    expect($migration)
        ->toContain("Schema::create('sales_target_copy_batches'")
        ->toContain("->constrained('sales_target_versions')->restrictOnDelete()")
        ->toContain("sales_target_copy_batch_profile_unique")
        ->toContain('Rollback refused: export and reconcile immutable Sales target copy lineage first.')
        ->and($model)
        ->toContain('Sales target copy batches are immutable.')
        ->toContain('Sales target copy batches cannot be deleted.');
});
