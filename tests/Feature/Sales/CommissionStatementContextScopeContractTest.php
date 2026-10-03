<?php

it('limits statement access and company actions to the selected Staff context', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionStatementController.php'));

    expect($controller)
        ->toContain('currentActorStaff($request->user())')
        ->toContain("where('staff_id', \$staff->id)")
        ->toContain("where('company_id', \$staff->company_id)")
        ->toContain("where('member.company_id', \$staff->company_id)")
        ->toContain("where('reporting.company_id', \$staff->company_id)->whereNull('reporting.deleted_at')")
        ->not->toContain("Staff::query()->where('user_id', \$request->user()->id)");
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
