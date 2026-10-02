<?php

it('creates governed EPF/ETF and gratuity statutory-policy tables with maker-checker and rollback protection', function () {
    $migration = file_get_contents(database_path('migrations/2026_08_24_100000_create_hr_payroll_statutory_policies.php'));

    expect($migration)->toContain("Schema::create('hr_epf_etf_contribution_policies'")
        ->toContain("Schema::create('hr_gratuity_policies'")
        ->toContain("\$table->decimal('employee_epf_rate_percent', 5, 2)")
        ->toContain("\$table->decimal('employer_epf_rate_percent', 5, 2)")
        ->toContain("\$table->decimal('employer_etf_rate_percent', 5, 2)")
        ->toContain("\$table->json('earnings_basis')")
        ->toContain("\$table->unsignedSmallInteger('minimum_qualifying_service_years')")
        ->toContain("\$table->unsignedSmallInteger('minimum_employer_headcount_threshold')")
        ->toContain("\$table->decimal('monthly_paid_divisor', 4, 2)")
        ->toContain("\$table->decimal('non_monthly_daily_wage_multiplier', 5, 2)")
        ->toContain("\$table->decimal('tax_exempt_threshold_lkr', 14, 2)->nullable()")
        ->toContain("\$table->decimal('tax_rate_above_threshold_percent', 5, 2)->nullable()")
        ->toContain('hr_epf_etf_policy_checker CHECK (approved_by IS NULL OR created_by <> approved_by)')
        ->toContain('hr_gratuity_policy_checker CHECK (approved_by IS NULL OR created_by <> approved_by)')
        ->toContain("hr_epf_etf_policy_version_unique")
        ->toContain("hr_gratuity_policy_version_unique")
        ->toContain('Refusing to drop governed EPF/ETF statutory-policy evidence while rows exist.')
        ->toContain('Refusing to drop governed gratuity statutory-policy evidence while rows exist.');
});

it('keeps statutory policy models governance-scoped without generic user-tracking columns', function () {
    $epfModel = file_get_contents(app_path('Models/Hr/HrEpfEtfContributionPolicy.php'));
    $gratuityModel = file_get_contents(app_path('Models/Hr/HrGratuityPolicy.php'));

    expect($epfModel)->toContain('protected $useUserTracking = false;')
        ->toContain("'earnings_basis' => 'array'")
        ->and($gratuityModel)->toContain('protected $useUserTracking = false;')
        ->toContain("'minimum_qualifying_service_years' => 'integer'");
});

it('enforces maker-checker approval and effective-period overlap for both statutory policy types', function () {
    $service = file_get_contents(app_path('Services/Hr/PayrollStatutoryPolicyService.php'));

    expect($service)->toContain("abort_unless(\$policy->status === 'draft', 422, 'Only a draft EPF/ETF contribution policy can be approved.')")
        ->toContain("abort_if(\$policy->created_by === \$actorUserId, 409, 'The policy preparer cannot approve the same version.')")
        ->toContain("abort_unless(\$policy->status === 'draft', 422, 'Only a draft gratuity policy can be approved.')")
        ->toContain("->where('status', 'approved')->where('id', '!=', \$policy->id)")
        ->toContain("An approved EPF/ETF contribution policy already overlaps this effective period.")
        ->toContain("An approved gratuity policy already overlaps this effective period.")
        ->toContain('lockForUpdate()->max(\'version\') + 1');
});

it('fails closed on preview when no approved statutory policy is effective and never invents a rate', function () {
    $service = file_get_contents(app_path('Services/Hr/PayrollStatutoryPolicyService.php'));

    expect($service)->toContain('No approved EPF/ETF contribution policy is effective for this legal entity and date.')
        ->toContain('No approved gratuity policy is effective for this legal entity and date.')
        ->toContain("->where('status', 'approved')")
        ->toContain("->where('effective_from', '<=', \$at)")
        ->not->toContain('0.08')
        ->not->toContain('0.12')
        ->not->toContain('8.00')
        ->not->toContain('12.00');
});

it('computes EPF/ETF contribution amounts from the resolved policy rates only', function () {
    $service = file_get_contents(app_path('Services/Hr/PayrollStatutoryPolicyService.php'));

    expect($service)->toContain("round(\$totalEarnings * (float) \$policy->employee_epf_rate_percent / 100, 2)")
        ->toContain("round(\$totalEarnings * (float) \$policy->employer_epf_rate_percent / 100, 2)")
        ->toContain("round(\$totalEarnings * (float) \$policy->employer_etf_rate_percent / 100, 2)");
});

it('computes gratuity from the configured monthly-half-wage or non-monthly daily-wage formula and blocks under the qualifying threshold', function () {
    $service = file_get_contents(app_path('Services/Hr/PayrollStatutoryPolicyService.php'));

    expect($service)->toContain('is below the configured minimum qualifying service')
        ->toContain("round(\$wageAmount / (float) \$policy->monthly_paid_divisor * \$completedYears, 2)")
        ->toContain("round(\$wageAmount * (float) \$policy->non_monthly_daily_wage_multiplier * \$completedYears, 2)")
        ->toContain('employer_meets_headcount_threshold')
        ->toContain('$currentEmployerHeadcount >= $policy->minimum_employer_headcount_threshold');
});

it('gates the payroll-statutory API behind the existing HR payroll feature flag and dedicated permissions', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PayrollStatutoryController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($controller)->toContain("config('hr.features.payroll', false)")
        ->toContain('HR Payroll is not enabled.')
        ->and($routes)->toContain("Route::prefix('hr/payroll')->middleware('ensure.internal')->group")
        ->toContain("permission:hr.payroll.statutory.view")
        ->toContain("permission:hr.payroll.statutory.manage")
        ->toContain("permission:hr.payroll.statutory.approve")
        ->toContain('epf-etf-policies/preview')
        ->toContain('gratuity-policies/preview');
});

it('registers the new statutory permissions deny-by-default with a maker-checker role split', function () {
    $permissions = file_get_contents(database_path('seeders/AllPermissionsSeeder.php'));

    expect($permissions)->toContain("'hr.payroll.statutory.view',")
        ->toContain("'hr.payroll.statutory.manage',")
        ->toContain("'hr.payroll.statutory.approve',");

    $denyBlock = substr($permissions, strpos($permissions, 'private static function denyByDefaultPermissions'));
    expect($denyBlock)->toContain("'hr.payroll.statutory.view',")
        ->toContain("'hr.payroll.statutory.manage',")
        ->toContain("'hr.payroll.statutory.approve',");
});
