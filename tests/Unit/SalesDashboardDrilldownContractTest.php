<?php

it('keeps the self dashboard free of client selected Staff identity and scopes the dedicated drilldown first', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($controller)
        ->toContain('public function staff(Request $request, string $staffId')
        ->toContain("\$this->scope->assertProfile(")
        ->toContain("\$request->attributes->set('sales_dashboard_profile', \$profile)")
        ->toContain("\$companyWide = \$ids === null && \$selectedProfile === null")
        ->and($routes)
        ->toContain("Route::prefix('sales-performance')")
        ->toContain("Route::get('management', [SalesDashboardController::class, 'show'])")
        ->toContain("Route::get('me', [SalesDashboardController::class, 'show'])")
        ->toContain("Route::get('staff/{staffId}', [SalesDashboardController::class, 'staff'])")
        ->toContain("permission:sales.performance.view-team|sales.performance.view-all");
});

it('routes a pure salesperson to the canonical self dashboard', function () {
    $contexts = file_get_contents(app_path('Services/UserContextService.php'));

    expect($contexts)->toContain("\$route = '/sales/me';");
});

it('bounds and paginates the stable authorized ranking without changing full-scope summaries', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));

    expect($controller)
        ->toContain("'ranking_per_page' => ['nullable', 'integer', 'min:1', 'max:100']")
        ->toContain("\$summary = collect(\$summaryFields)->mapWithKeys(fn (\$field) => [\$field => (float) \$allRows->sum(\$field)])->all()")
        ->toContain("\$rankingRows = \$allRows->forPage(\$rankingPage, \$rankingPerPage)->values()")
        ->toContain("\$rankingLastPage = max(1, (int) ceil(\$rankingTotal / \$rankingPerPage))")
        ->toContain("\$rankingPage = min((int) (\$data['ranking_page'] ?? 1), \$rankingLastPage)")
        ->toContain("'rows' => \$ranking");
});

it('fails target achievement closed unless every requested month has one approved target', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));

    expect($service)
        ->toContain("'ambiguous_configuration'")
        ->toContain("'partial_configuration'")
        ->toContain("'configured_zero'")
        ->toContain("'amount_lkr' => \$complete ? round(\$amount, 4) : null")
        ->toContain("'expected_month_count' => \$periods->count()")
        ->toContain("'missing_period_starts' => \$missing")
        ->toContain("'ambiguous_period_starts' => \$ambiguous")
        ->toContain("'business_timezone' => \$businessTimezone")
        ->toContain('An approved Sales business timezone is required for target period calculations.')
        ->toContain("'period_basis' => (\$newSales['proration_applied'] || \$collections['proration_applied'])")
        ->toContain("'target_configuration_snapshot' => \$target")
        ->toContain("'new_sales_target_variance_lkr' => \$newTarget === null ? null")
        ->and($controller)
        ->toContain("\$complete = \$allRows->isNotEmpty() && \$states->every")
        ->toContain("\$summary[\"{\$key}_target_lkr\"] = \$complete ? (float) \$allRows->sum(\$targetFields['amount']) : null")
        ->toContain("\$summary[\"{\$key}_target_missing_profile_count\"]");
});

it('returns stable bounded pipeline dues and alert pages inside the resolved Profile scope', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));

    expect($controller)
        ->toContain("'pipeline_per_page' => ['nullable', 'integer', 'min:1', 'max:25']")
        ->toContain("'dues_per_page' => ['nullable', 'integer', 'min:1', 'max:100']")
        ->toContain("'alerts_per_page' => ['nullable', 'integer', 'min:1', 'max:100']")
        ->toContain("->where('company_id', \$companyId)->whereIn('owner_sales_profile_id', \$ids)")
        ->toContain("->whereNull('schedule.deleted_at')->whereIn('attribution.collection_sales_profile_id', \$ids)")
        ->toContain("->where('alert.company_id', \$companyId)->whereIn('alert.sales_profile_id', \$ids)")
        ->toContain("->orderBy('schedule.due_date')->orderBy('schedule.id')")
        ->toContain("->orderByDesc('alert.detected_at')->orderBy('alert.id')")
        ->toContain('private function paginateQuery(')
        ->toContain('private function paginateCollection(')
        ->toContain('private function pageEnvelope(')
        ->not->toContain("->orderByDesc('alert.detected_at')->limit(50)");
});

it('reconciles each frozen trend point to paginated authorized source facts', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($controller)
        ->toContain('snapshot.id snapshot_id, snapshot.version snapshot_version')
        ->toContain("->whereDate('snapshot.period_end', '<=', \$data['to'])")
        ->toContain("->orderByDesc('snapshot.period_end')->orderByDesc('snapshot.version')->limit(24)")
         ->toContain('public function trendFacts(Request $request, string $snapshot)')
         ->toContain("'metric' => ['required', Rule::in(['new_sales', 'eligible_collections', 'commission'])]")
         ->toContain("'collection_cohort' => ['nullable', Rule::in(['current_period_booking', 'prior_period_booking'])]")
         ->toContain("'commission_category' => ['nullable', Rule::in(['one_time', 'long_term'])]")
         ->toContain('Frozen trend snapshots expose cohort and category as independent marginal totals')
         ->toContain("\$this->scope->profileIds(")
         ->toContain("->whereBetween('fact.occurred_on', [\$frozen->period_start, \$frozen->period_end])")
         ->toContain("->where('fact.occurred_at', '<=', \$frozen->cutoff_at)")
         ->toContain('$this->metricBreakdowns->resolve(')
         ->toContain('private function frozenTrendTotals(')
         ->toContain("array_key_exists('commission_earned_lkr', \$metrics)")
         ->toContain("'reconciliation_status' => ! \$breakdownIncomplete && \$frozenAmount !== null")
         ->toContain("'frozen_split_state' => \$frozenTotals['state']")
         ->toContain("\$fact->canonical_ids = collect(\$dimensions)->only")
        ->not->toContain("\$fact->dimensions =")
        ->and($routes)
        ->toContain("Route::get('snapshots/{snapshot}/facts', [SalesDashboardController::class, 'trendFacts'])")
        ->toContain("->whereUuid('snapshot')->middleware('permission:sales.performance.view')");
});

it('drills a pipeline stock point to the same current authorized opportunity source', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($controller)
        ->toContain('public function pipelineFacts(Request $request): JsonResponse')
        ->toContain("'stage' => ['required', Rule::in(['new', 'contacted', 'qualified', 'quotation', 'negotiation'])]")
        ->toContain("\$this->dashboardProfileIds(")
        ->toContain("->where('opportunity.company_id', \$data['company_id'])")
        ->toContain("->whereIn('opportunity.owner_sales_profile_id', \$authorizedIds)")
        ->toContain("'source_totals' => ['count' => \$sourceCount")
        ->toContain("'missing_lkr_count' => \$missingLkrCount")
        ->not->toContain("'opportunity.prospect_email'")
        ->not->toContain("'opportunity.prospect_phone'")
        ->toContain("'stock_definition' => 'Current open opportunities in the selected stage; never summed across periods.'")
        ->toContain("'canonical_record_access' => 'Opening the CRM workflow requires sales.crm.view")
        ->and($routes)
        ->toContain("Route::get('pipeline/facts', [SalesDashboardController::class, 'pipelineFacts'])")
        ->toContain("->middleware('permission:sales.performance.view')");
});

it('aligns mutable opportunity and immutable Sales event persistence contracts', function () {
    $opportunity = file_get_contents(app_path('Models/Sales/SalesOpportunity.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_14_105000_align_sales_opportunity_soft_delete_contract.php'));

    expect($opportunity)->toContain('class SalesOpportunity extends BaseModel')
        ->and($migration)
        ->toContain("Schema::hasColumn('sales_opportunities', 'deleted_at')")
        ->toContain('$table->softDeletes()')
        ->toContain('$table->dropSoftDeletes()');

    foreach (['SalesOpportunityStageEvent', 'SalesActivity', 'SalesPerformanceAlertEvent'] as $immutableModel) {
        $source = file_get_contents(app_path("Models/Sales/{$immutableModel}.php"));
        expect($source)
            ->toContain("class {$immutableModel} extends Model")
            ->toContain('use HasUuids;')
            ->not->toContain('extends BaseModel');
    }
});

it('drills an outstanding schedule to source-currency allocations and governed LKR basis', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($controller)
        ->toContain('public function scheduleFacts(Request $request, string $schedule): JsonResponse')
        ->toContain("COALESCE(schedule.source_amount, schedule.amount)")
        ->toContain("COALESCE(schedule.source_currency, 'LKR')")
        ->toContain('schedule.lkr_amount * ({$outstandingExpression}) / {$sourceExpression}')
        ->toContain("->whereIn('attribution.collection_sales_profile_id', \$authorizedIds)")
        ->toContain("'balance_equation' => 'scheduled source amount - net source allocations = outstanding source amount'")
        ->toContain("'booking_payment_receipt_id', 'amount', 'allocated_at'")
        ->and($routes)
        ->toContain("Route::get('schedules/{schedule}/facts', [SalesDashboardController::class, 'scheduleFacts'])")
        ->toContain("->whereUuid('schedule')->middleware('permission:sales.performance.view')");
});

it('drills current KPI flows to authorized signed facts before pagination', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));
    $performance = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));
    $breakdowns = file_get_contents(app_path('Services/Sales/SalesMetricBreakdownService.php'));
    $periodClose = file_get_contents(app_path('Services/Sales/SalesPeriodCloseService.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_14_106000_align_sales_booking_attribution_soft_delete_contract.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($controller)
        ->toContain('public function kpiFacts(Request $request): JsonResponse')
        ->toContain("Rule::in(['new_sales', 'eligible_collections', 'commission'])")
        ->toContain("'eligible_collections' => 'eligible_collection'")
        ->toContain("->whereIn('fact.sales_profile_id', \$authorizedIds)")
        ->toContain("->where('fact.business_classification', 'new_business')")
        ->toContain("->whereIn('fact.business_classification', ['new_business', 'recurring_business'])")
        ->toContain("->whereBetween('fact.occurred_on', [\$data['from'], \$data['to']])")
        ->toContain("->where('fact.occurred_at', '<=', \$asOf)")
        ->toContain("'collection_cohort' => ['nullable', Rule::in(['current_period_booking', 'prior_period_booking'])]")
        ->toContain("'commission_category' => ['nullable', Rule::in(['one_time', 'long_term'])]")
        ->toContain('$this->metricBreakdowns->resolve(')
        ->toContain("'source_amount_lkr' => \$sourceAmount")
        ->toContain("'unfiltered_source_amount_lkr' => \$unfilteredAmount")
        ->toContain("'breakdown' => \$breakdown")
        ->toContain("'booking_id', 'customer_id', 'receipt_id', 'payment_schedule_id'")
        ->toContain('unset($fact->dimensions)')
        ->toContain("'facts' => \$page")
        ->and($performance)
        ->toContain("\$newSales = \$metric('new_sales', 'new_business')")
        ->toContain("\$metric('new_sales', 'new_business', 'quantity')")
        ->toContain("'collection_cohort_state' => \$collectionCohortMissing === 0 ? 'complete' : 'incomplete'")
        ->toContain("'commission_category_state' => \$commissionCategoryMissing === 0 ? 'complete' : 'incomplete'")
        ->and($breakdowns)
        ->toContain('SalesBookingAttribution::withTrashed()')
        ->toContain("'current_period_booking'")
        ->toContain("'prior_period_booking'")
        ->toContain("['one_time', 'long_term']")
        ->and($periodClose)
        ->toContain("'collection_cohort_missing_count', 'commission_cohort_missing_count'")
        ->and($migration)
        ->toContain("Schema::hasColumn('sales_booking_attributions', 'deleted_at')")
        ->toContain('$table->softDeletes()')
        ->toContain("DB::table('sales_booking_attributions')->whereNotNull('deleted_at')->exists()")
        ->toContain('$table->dropSoftDeletes()')
        ->and($routes)
        ->toContain("Route::get('kpis/facts', [SalesDashboardController::class, 'kpiFacts'])")
        ->toContain("->middleware('permission:sales.performance.view')");
});

it('keeps commission stocks separate from paid period flow and fails closed for historical stock', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));
    $statuses = file_get_contents(app_path('Services/Sales/SalesCommissionStatusService.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($statuses)
        ->toContain("'stock_state' => \$stockAvailable ? 'current' : 'historical_reconstruction_unavailable'")
        ->toContain("->whereIn('statement.status', ['draft', 'pending_approval', 'approved', 'partially_paid'])")
        ->toContain("->whereIn('decision.status', ['held', 'shadow_held'])")
        ->toContain("->whereNull('release.id')->whereNull('adjustment.id')->whereNull('resolution.id')")
        ->toContain("->where('payout.status', 'confirmed')")
        ->toContain("'held_eligible_basis_lkr'")
        ->and($controller)
        ->toContain('public function commissionStatus(Request $request): JsonResponse')
        ->toContain("Rule::in(['pending', 'held', 'approved', 'paid'])")
        ->toContain('Historical commission stock reconstruction is unavailable')
        ->toContain("'canonical_record_access' => 'Statements and payouts require their separately permissioned")
        ->and($routes)
        ->toContain("Route::get('commission-status', [SalesDashboardController::class, 'commissionStatus'])")
        ->toContain("->middleware('permission:sales.performance.view')");
});

it('derives current collection aging from canonical schedules and net allocations', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));
    $aging = file_get_contents(app_path('Services/Sales/SalesCollectionAgingStatusService.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($aging)
        ->toContain("COALESCE(schedule.source_amount, schedule.amount)")
        ->toContain("{\$sourceExpression} - COALESCE(allocated.net_amount,0)")
        ->toContain("->whereIn('attribution.collection_sales_profile_id', \$profileIds)")
        ->toContain("'historical_reconstruction_unavailable'")
        ->toContain("'not_due', 'due_today', '1_30', '31_60', '61_90', '91_plus'")
        ->toContain("'outstanding_lkr_state'")
        ->toContain("'sales_eligible_outstanding_lkr'")
        ->toContain('is_collection_target_eligible')
        ->toContain("'source_currency_breakdown'")
        ->and($controller)
        ->toContain('public function collectionAging(Request $request): JsonResponse')
        ->toContain('Historical collection aging reconstruction is unavailable')
        ->toContain("'canonical_record_access' => 'Booking Management requires the separate bookings.view permission.'")
        ->and($routes)
        ->toContain("Route::get('collection-aging', [SalesDashboardController::class, 'collectionAging'])")
        ->toContain("->middleware('permission:sales.performance.view')");
});

it('projects latest frozen monthly target lines and comparable direction only', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($controller)
        ->toContain("MAX(version) latest_version")
        ->toContain("->where('status', 'frozen')->where('period_type', 'month')")
        ->toContain("->on('latest.latest_version', '=', 'snapshot.version')")
        ->toContain("'new_sales_target' => ['state' => 'new_sales_target_state'")
        ->toContain("'collection_target' => ['state' => 'collection_target_state'")
        ->toContain("'no_prior_month'")
        ->toContain("'non_consecutive_month'")
        ->toContain("'scope_changed'")
        ->toContain('public function trendTargets(Request $request, string $snapshot): JsonResponse')
        ->toContain("->whereIn('row.sales_profile_id', \$authorizedIds)")
        ->toContain("'target_versions' => \$versions")
        ->toContain("missing and approved zero remain distinct")
        ->and($routes)
        ->toContain("Route::get('snapshots/{snapshot}/targets', [SalesDashboardController::class, 'trendTargets'])")
        ->toContain("->whereUuid('snapshot')->middleware('permission:sales.performance.view')");
});
