<?php

it('versions tenant decisions under locks with checksum bound retries and immutable audit', function () {
    $migration = file_get_contents(database_path('migrations/2026_09_09_120000_create_tenant_decision_versions_table.php'));
    $service = file_get_contents(app_path('Services/TenantDecisionMutationService.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Admin/TenantDecisionController.php'));
    $portal = file_get_contents(base_path('../portal-thetaxi/src/app/modules/admin/system/tenant-decisions/tenant-decisions.component.ts'));

    expect($migration)->toContain("Schema::create('tenant_decision_versions'", 'tenant_decision_company_key_version_unique', 'tenant_decision_company_idempotency_unique', 'tenant_decision_approval_idempotency_unique', "date('effective_from')", 'Rollback refused: effective-dated or superseded tenant decision history must be retained.')
        ->and($service)->toContain('CanonicalJson::encode', 'lockForUpdate()', 'request_checksum', 'approval_request_checksum', "'tenant_decision_drafted'", "'tenant_decision_approved'", "'domain_audit_events'", 'projectApproved(', "where('status', 'draft')->orderByDesc('version')")
        ->and(substr_count($service, '$this->projectApproved('))->toBe(2)
        ->and($controller)->toContain('TenantDecisionMutationService', "'idempotency_key' => 'required|uuid'", "'effective_from' => 'required|date_format:Y-m-d'", "'pending_approval'", "'active_version'")
        ->and($portal)->toContain('approveTenantDecision(d.key, companyId, crypto.randomUUID())');
});
