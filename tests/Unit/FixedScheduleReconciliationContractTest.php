<?php

it('keeps fixed schedule revisions exact, preview-bound, immutable, and allocation preserving', function (): void {
    $service = file_get_contents(app_path('Services/Sales/CollectionScheduleWorkflowService.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CollectionScheduleWorkflowController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_13_135000_add_fixed_schedule_reconciliation_evidence.php'));

    expect($service)
        ->toContain('previewFutureUnpaidRevision')
        ->toContain('previewChecksum')
        ->toContain("'allocated_amount' => (string) (\$schedule->allocations_sum_amount ?? 0)")
        ->toContain("unset(\$payload['idempotency_key'], \$payload['preview_checksum'])")
        ->toContain('The fixed schedule preview is stale')
        ->toContain('must equal the frozen contractual source amount exactly')
        ->toContain('must equal the frozen contractual LKR amount exactly')
        ->toContain("'contractual_lkr_amount' => \$reconciliation['summary']['contractual_lkr_amount']")
        ->toContain("'allocations_moved' => false")
        ->toContain("'reconciliation_role' => \$item['reconciliation_role'] ?? null")
        ->and($controller)
        ->toContain("'revisions' => \$revisions")
        ->toContain("Rule::in(['exact', 'opening', 'balloon', 'residual'])")
        ->toContain("'preview_checksum' => ['required', 'string', 'size:64']")
        ->and($routes)
        ->toContain('collection-schedule/revision-preview')
        ->and($migration)
        ->toContain('Refusing to drop fixed schedule reconciliation evidence.')
        ->toContain("\$table->softDeletes()");
});
