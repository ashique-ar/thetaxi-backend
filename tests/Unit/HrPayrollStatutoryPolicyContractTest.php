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
    $approvalBody = static function (string $start, string $end) use ($service): string {
        $startAt = strpos($service, $start);
        $endAt = $startAt === false ? false : strpos($service, $end, $startAt);

        return $startAt === false || $endAt === false ? '' : substr($service, $startAt, $endAt - $startAt);
    };
    $companyLock = "DB::table('companies')->where('id', \$companyId)->lockForUpdate()->first();";

    expect($service)->toContain("abort_unless(\$policy->status === 'draft', 422, 'Only a draft EPF/ETF contribution policy can be approved.')")
        ->toContain("abort_if(\$policy->created_by === \$actorUserId, 409, 'The policy preparer cannot approve the same version.')")
        ->toContain("abort_unless(\$policy->status === 'draft', 422, 'Only a draft gratuity policy can be approved.')")
        ->toContain('An explicit statutory tax threshold and rate are required before approval.')
        ->toContain("->where('status', 'approved')->where('id', '!=', \$policy->id)")
        ->toContain("An approved EPF/ETF contribution policy already overlaps this effective period.")
        ->toContain("An approved gratuity policy already overlaps this effective period.")
        ->toContain('lockForUpdate()->max(\'version\') + 1');

    foreach ([
        $approvalBody('public function approveEpfEtfPolicy', 'public function resolveEffectiveEpfEtfPolicy'),
        $approvalBody('public function approveGratuityPolicy', 'public function resolveEffectiveGratuityPolicy'),
    ] as $body) {
        $lockAt = strpos($body, $companyLock);
        $overlapAt = strpos($body, '$overlap =');
        expect($lockAt)->not->toBeFalse()
            ->and($overlapAt)->not->toBeFalse()
            ->and($lockAt)->toBeLessThan($overlapAt);
    }
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

    expect($service)->toContain("round(\$assessableEarnings * ((float) \$policy->employee_epf_rate_percent / 100), 2)")
        ->toContain("round(\$assessableEarnings * ((float) \$policy->employer_epf_rate_percent / 100), 2)")
        ->toContain("round(\$assessableEarnings * ((float) \$policy->employer_etf_rate_percent / 100), 2)")
        ->toContain('! is_finite($totalEpf)')
        ->toContain('The calculated contribution exceeds the supported numeric range.');
});

it('computes monthly gratuity and blocks non-monthly gratuity without wage-history authority', function () {
    $service = file_get_contents(app_path('Services/Hr/PayrollStatutoryPolicyService.php'));

    expect($service)->toContain('is below the configured minimum qualifying service')
        ->toContain("round(\$wageAmount / (float) \$policy->monthly_paid_divisor * \$completedYears, 2)")
        ->toContain('employer_meets_headcount_threshold')
        ->toContain('$currentEmployerHeadcount >= $policy->minimum_employer_headcount_threshold')
        ->toContain("if (! is_finite(\$wageAmount) || \$wageAmount < 0)")
        ->toContain("if (\$completedYears < 0 || \$completedYears > 80)")
        ->toContain("if (\$currentEmployerHeadcount !== null && \$currentEmployerHeadcount < 0)")
        ->toContain('gratuityPolicyIsComplete($policy)')
        ->toContain("if (\$payBasis === 'non_monthly')")
        ->toContain('Non-monthly gratuity is blocked until an authoritative wage-history source is configured.')
        ->toContain("'non_monthly_lookback_months' => \$policy->non_monthly_lookback_months")
        ->toContain('Current employer headcount is required to evaluate the approved gratuity policy threshold.')
        ->not->toContain("round(\$wageAmount * (float) \$policy->non_monthly_daily_wage_multiplier * \$completedYears, 2)");
});

it('gates the payroll-statutory API behind the existing HR payroll feature flag and dedicated permissions', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PayrollStatutoryController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($controller)->toContain("config('hr.features.payroll', false)")
        ->toContain('HR Payroll is not enabled.')
        ->toContain("'tax_exempt_threshold_lkr' => ['required', 'numeric', 'min:0']")
        ->toContain("'tax_rate_above_threshold_percent' => ['required', 'numeric', 'min:0', 'max:100']")
        ->toContain("'current_employer_headcount' => ['required', 'integer', 'min:0']")
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

it('does not prefill statutory payroll rates, thresholds, formulas, or earnings coverage in the UI', function () {
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/payroll-statutory-configuration/payroll-statutory-configuration.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/payroll-statutory-configuration/payroll-statutory-configuration.component.html'));

    expect($component)
        ->toContain('employee_epf_rate_percent: [null as number | null')
        ->toContain('include_basic_salary: [null as boolean | null, Validators.required]')
        ->toContain('minimum_qualifying_service_years: [null as number | null')
        ->toContain('Validators.max(50)', 'Validators.max(100000)', 'Validators.max(12)', 'Validators.max(365)', 'Validators.max(36)')
        ->toContain("pay_basis: [null as 'monthly' | 'non_monthly' | null, Validators.required]")
        ->toContain('wage_amount: [null as number | null', 'completed_years: [null as number | null', 'Validators.max(80)')
        ->toContain('this.epfEtfPreviewForm.valueChanges.subscribe', 'this.gratuityPreviewForm.valueChanges.subscribe', 'revision === this.epfPreviewRevision', 'revision === this.gratuityPreviewRevision')
        ->toContain('tax_exempt_threshold_lkr: [null as number | null, [Validators.required, Validators.min(0)]]')
        ->toContain('tax_rate_above_threshold_percent: [null as number | null, [Validators.required, Validators.min(0), Validators.max(100)]]')
        ->toContain('current_employer_headcount: [null as number | null, [Validators.required, Validators.min(0)]]')
        ->not->toContain('employee_epf_rate_percent: [8', 'employer_epf_rate_percent: [12', 'employer_etf_rate_percent: [3', 'minimum_qualifying_service_years: [5', 'minimum_employer_headcount_threshold: [15', 'monthly_paid_divisor: [2', 'non_monthly_daily_wage_multiplier: [14', 'tax_exempt_threshold_lkr: [5000000', 'tax_rate_above_threshold_percent: [12')
        ->and($template)
        ->toContain('formControlName="include_basic_salary"', '[value]="false">Exclude', 'formControlName="exclude_overtime"', 'formControlName="tax_exempt_threshold_lkr" required', 'formControlName="tax_rate_above_threshold_percent" required', 'formControlName="current_employer_headcount" required')
        ->not->toContain('Current employer headcount (optional)')
        ->and($template)->toContain('Non-monthly gratuity previews are blocked until an authoritative wage-history source is configured.', 'max="50"', 'max="100000"', 'max="12"', 'max="365"', 'max="36"', 'max="80"')
        ->not->toContain('average daily wage supplied by the operator', 'Average daily wage for approved lookback (LKR)')
        ->not->toContain("pay_basis: ['monthly'", 'wage_amount: [0,', 'completed_years: [0,');
});

it('validates and sends every earnings category required by the approved EPF/ETF basis', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PayrollStatutoryController.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/payroll-statutory-configuration/payroll-statutory-configuration.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/payroll-statutory-configuration/payroll-statutory-configuration.component.html'));

    foreach (['basic_salary', 'cost_of_living_allowance', 'food_allowance', 'holiday_pay', 'other_regular_allowances', 'overtime', 'bonus', 'reimbursements'] as $field) {
        expect($controller)->toContain("'earnings.{$field}' => ['required', 'numeric', 'min:0']")
            ->and($component)->toContain("{$field}: [null as number | null")
            ->and($template)->toContain("formControlName=\"{$field}\" required");
    }
    expect($controller)->toContain("->previewContribution(\$companyId, \$data['earnings'], \$at)")
        ->and($controller)->not->toContain("'total_earnings' => ['required'")
        ->and($template)->toContain('preview.assessable_earnings')
        ->and($component)->toContain('previewEpfEtfContribution({ earnings: this.epfEtfPreviewForm.getRawValue() })')
        ->and($component)->not->toContain('total_earnings');
});

it('returns only confirmation fields from statutory policy writes', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PayrollStatutoryController.php'));

    expect($controller)
        ->toContain("'status' => \$policy->status", "'version' => \$policy->version", "'effective_from' => \$policy->effective_from")
        ->toContain('$this->policyActionResponse($policy, 201)', '$this->policyActionResponse($policy)')
        ->not->toContain("'data' => \$policy");
});

it('projects statutory policy lists for the portal without returning tenant or user identifiers', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PayrollStatutoryController.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/payroll-statutory-configuration/payroll-statutory-configuration.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-workforce/components/payroll-statutory-configuration/payroll-statutory-configuration.component.html'));

    expect($controller)
        ->toContain('epfEtfPolicyProjection(', 'gratuityPolicyProjection(')
        ->toContain("'earnings_basis' => \$policy->earnings_basis", "'statutory_reference' => \$policy->statutory_reference")
        ->toContain("'tax_exempt_threshold_lkr' => \$policy->tax_exempt_threshold_lkr", "'tax_rate_above_threshold_percent' => \$policy->tax_rate_above_threshold_percent")
        ->toContain("'can_approve' => \$policy->status === 'draft' && \$policy->created_by !== \$actorUserId")
        ->not->toContain("'company_id' => \$policy->company_id", "'created_by' => \$policy->created_by", "'approved_by' => \$policy->approved_by")
        ->and($component)->toContain('row.can_approve === true', 'earningsBasisSummary(row.earnings_basis)')
        ->and($template)->toContain('row.statutory_reference', 'row.reason', 'row.tax_exempt_threshold_lkr', 'row.tax_rate_above_threshold_percent');
});
