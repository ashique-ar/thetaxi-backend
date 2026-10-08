<?php

it('projects Commission recovery reads and writes to fields the Portal uses', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionRecoveryController.php'));

    expect($controller)
        ->toContain(
            "'event_version', 'recovery_kind', 'status'",
            "'entitlement_source_type', 'entitlement_amount_lkr', 'reporting_lkr_delta'",
            "'calculation_status', 'calculation_explanation'",
            "'decision' => \$case->decision?->only(['resolution_disposition', 'commission_adjustment_lkr'])",
            "\$decision->only(['id'])",
        )
        ->not->toContain("'data' => \$recoveries->decide(");
});

it('projects Commission hold reads and writes without serializing decision evidence', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionHoldController.php'));
    $portal = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/services/sales.service.ts'));
    $start = strpos($portal, 'export interface CommissionHoldRow {');
    $end = strpos($portal, 'export interface CommissionHoldPreview', $start);
    $rowType = substr($portal, $start, $end - $start);

    expect($controller)
        ->toContain(
            "'eligible_lkr_amount', 'status', 'hold_code', 'calculation_explanation'",
            "'remediation' => Arr::only(",
            "'notification_delivery' => \$this->notifications->latestStatus(\$decision->id)",
            "'hold_release' => \$decision->holdRelease?->only(['release_kind', 'commission_amount_lkr', 'released_at'])",
            "'data' => \$row->only(['id'])",
            "'data' => \$adjustment->only(['id'])",
        )
        ->not->toContain("'data' => \$earning");
    expect($rowType)->not->toContain(
        'booking_id', 'receipt_id', 'beneficiary_sales_profile_id', 'beneficiary_staff_id',
        'calculation_checksum', 'request_payload_checksum', 'approved_by',
    );
});

it('preselects the authorized default company for recovery and hold filters', function () {
    $recovery = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionRecoveryController.php'));
    $hold = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionHoldController.php'));
    $portal = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-financial-corrections/sales-financial-corrections.component.ts'));
    $holdPortal = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-commission-hold-operations/sales-commission-hold-operations.component.ts'));
    $recoveryHtml = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-financial-corrections/sales-financial-corrections.component.html'));
    $holdHtml = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-commission-hold-operations/sales-commission-hold-operations.component.html'));
    $selector = file_get_contents(base_path('../portal-thetaxi/src/app/shared/components/ui/managed-record-select/managed-record-select.component.ts'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($recovery)->toContain("companyIds(\$request->user(), 'sales.commission-recoveries.view-all')", 'default_company_id', 'selected_id', 'search', "where('company_id', \$id)", "where('is_active', true)");
    expect($hold)->toContain("companyIds(\$request->user(), 'sales.commission-decisions.view-all')", 'default_company_id', 'selected_id', 'search', "where('company_id', \$id)", "where('is_active', true)");
    expect($portal)->toContain('company_id:this.recoveryCompanyId', 'recoveryCompanyChanged(companyId: string)', 'recoveryRequestVersion');
    expect($holdPortal)->toContain('company_id: this.companyId', 'companyChanged(companyId: string)', 'loadVersion');
    expect($recoveryHtml)->toContain('endpoint="/sales/commission-recoveries/company-options"', 'Search authorized companies');
    expect($holdHtml)->toContain('endpoint="/sales/commission-holds/company-options"', 'Search authorized companies');
    expect($selector)->toContain('default_company_id', 'is_default', 'this.onChange(this.value)');
    expect($routes)->toContain("commission-recoveries/company-options", "commission-holds/company-options");
});

it('locks an active company before deciding a commission recovery case', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionRecoveryService.php'));
    $start = strpos($service, 'public function decide(');
    $end = strpos($service, 'private function allowedDecisions(', $start);
    $decision = substr($service, $start, $end - $start);

    expect(strpos($decision, "DB::table('companies')"))->toBeLessThan(strpos($decision, 'SalesCommissionRecoveryCase::query()->lockForUpdate()'))
        ->and($decision)->toContain("where('is_active', true)", "whereNull('deleted_at')");
});
