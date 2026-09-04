<?php

it('governs manual target drafts and approvals as idempotent maker-checker commands', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPerformanceController.php'));
    $service = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($controller)
        ->toContain("'reason' => ['required', 'string', 'min:10', 'max:2000']")
        ->toContain("'idempotency_key' => ['required', 'string', 'max:160']")
        ->toContain("'expected_version' => ['required', 'integer', 'min:1']")
        ->and($service)
        ->toContain('target-draft idempotency key was reused with different evidence')
        ->toContain('target-approval idempotency key was reused with different evidence')
        ->toContain('Target maker and approver must be different users.')
        ->toContain('The target version is stale; refresh the target history before approval.')
        ->toContain('The Sales performance period is locked; reopen it before approving a replacement target.')
        ->toContain("DB::table('companies')->whereKey(\$data['company_id'])->lockForUpdate()")
        ->toContain("->whereDate('period_start', \$start)->whereDate('period_end', \$end)->max('version') + 1")
        ->toContain('sales.performance.target_draft_created')
        ->toContain('sales.performance.target_approved')
        ->toContain('The original target-draft audit evidence is unavailable; the command cannot be replayed safely.')
        ->toContain('The original target-approval audit evidence is unavailable; the command cannot be replayed safely.')
        ->toContain("DB::table('domain_audit_events')->insert")
        ->toContain("'source_ip' => \$sourceIp")
        ->toContain("'before_checksum' => \$beforeChecksum, 'after_checksum' => \$afterChecksum")
        ->toContain("\$target->setAttribute('audit_event_id', \$auditEventId)")
        ->toContain("\$target->setAttribute('outbox_event_id', \$outboxEventId)")
        ->toContain("\$target->setAttribute('correlation_id', \$correlationId)")
        ->toContain("\$target->setAttribute('idempotent_replay', \$idempotentReplay)")
        ->toContain("'superseded_target_ids' => \$supersededIds")
        ->and($routes)
        ->toContain("Route::post('targets/versions', [SalesPerformanceController::class, 'createTarget'])")
        ->toContain("Route::post('targets/versions/{target}/approve', [SalesPerformanceController::class, 'approveTarget'])");
});

it('keeps target history legal-entity scoped filtered bounded and stably ordered', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPerformanceController.php'));

    expect($controller)
        ->toContain("'company_id' => ['required', 'uuid', 'exists:companies,id']")
        ->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:100']")
        ->toContain("->where('sales_target_versions.company_id', \$data['company_id'])")
        ->toContain("->when(\$data['sales_profile_id'] ?? null")
        ->toContain("->when(\$data['month'] ?? null")
        ->toContain("->when(\$data['status'] ?? null")
        ->toContain("->orderByDesc('sales_target_versions.period_start')->orderBy('sales_target_versions.sales_profile_id')")
        ->toContain("\$page = min((int) (\$data['page'] ?? 1), \$lastPage)");
});

it('adds rollback-safe nullable command evidence without rewriting existing target rows', function () {
    $migration = file_get_contents(database_path('migrations/2026_08_14_104000_govern_manual_sales_target_commands.php'));
    $model = file_get_contents(app_path('Models/Sales/SalesTargetVersion.php'));

    expect($migration)
        ->toContain("Schema::table('sales_target_versions'")
        ->toContain("'draft_idempotency_key', 160")
        ->toContain("'approval_idempotency_key', 160")
        ->toContain("'approval_request_checksum', 64")
        ->toContain("\$table->dropUnique('sales_target_draft_idem_unique')")
        ->toContain("\$table->dropUnique('sales_target_approval_idem_unique')")
        ->and($model)->toContain("protected \$hidden = ['draft_idempotency_key', 'approval_idempotency_key']");
});
