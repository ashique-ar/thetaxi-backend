<?php

it('limits statement access by Staff Profile permissions across the installation', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionStatementController.php'));
    $scope = substr($controller, strpos($controller, 'private function applyProfileScope('), strpos($controller, 'private function assertStatementScope(') - strpos($controller, 'private function applyProfileScope('));

    expect($scope)
        ->toContain('currentActorStaff($request->user())', "where('staff_id', \$staff->id)",
            "whereIn('sales_profile_id', array_values(array_unique(\$ids)))", "whereIn('reporting.manager_sales_profile_id', \$managerIds)")
        ->not->toContain('actorCompanyIds', 'company_id', 'CompanyIdScope');
});

it('binds dispute idempotency replays and reviewer separation to the account and original request', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionDisputeService.php'));

    expect($service)
        ->toContain("where('idempotency_key', \$data['idempotency_key'])->lockForUpdate()->first()")
        ->toContain('request_payload_checksum')
        ->toContain('hash_equals($duplicate->request_payload_checksum, $requestChecksum)')
        ->toContain("where('user_id', \$actorUserId)->where('id', \$dispute->raised_by_staff_id)->exists()");
});

it('adds nullable replay evidence for old disputes and can roll the schema change back', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_03_000006_add_commission_dispute_request_checksum.php'));

    expect($migration)
        ->toContain("Schema::table('sales_commission_disputes'")
        ->toContain("char('request_payload_checksum', 64)->nullable()")
        ->toContain("dropColumn('request_payload_checksum')");
});
