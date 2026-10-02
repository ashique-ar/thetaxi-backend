<?php

it('classifies employment exit before mutable Profile eligibility checks', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionDecisionService.php'));

    expect($service)
        ->toContain("with(['staff' => fn (\$query) => \$query->withTrashed()])")
        ->toContain("return \$this->hold(\$base, 'employment_inactive'")
        ->toContain("\$this->employmentExitResolutions->resolve(\$decision)");

    expect(strpos($service, "return \$this->hold(\$base, 'employment_inactive'"))
        ->toBeLessThan(strpos($service, "isEligibleAt(\$profile, ['collection']"));
});

it('appends one evidence-bound zero entitlement resolution without financial projection', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionEmploymentExitResolutionService.php'));

    expect($service)
        ->toContain("\$decision->hold_code !== 'employment_inactive'")
        ->toContain("\$staff->employment_ended_at->gt(\$receipt->received_at)")
        ->toContain("'resolution_kind' => 'employment_exit_no_entitlement'")
        ->toContain("'commission_entitlement_lkr' => '0.0000'")
        ->toContain("'creates_metric_fact' => false")
        ->toContain("'creates_statement_line' => false")
        ->toContain("'creates_payout' => false")
        ->toContain("'creates_recovery' => false")
        ->not->toContain('termination_reason');
});

it('keeps employment evidence private and makes the additive schema rollback safe', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionHoldController.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_15_104000_govern_employment_exit_commission_resolutions.php'));

    expect($controller)
        ->toContain("'employment_ended_at', 'original_hold_code'")
        ->not->toContain("'employment_terminated_by', 'original_hold_code'")
        ->and($migration)
        ->toContain("'employment_exit_no_entitlement'")
        ->toContain("foreignUuid('employment_staff_id')->nullable()")
        ->toContain("foreignUuid('employment_terminated_by')->nullable()")
        ->toContain('Rollback refused: export and reconcile immutable employment-exit commission resolutions first.');
});
