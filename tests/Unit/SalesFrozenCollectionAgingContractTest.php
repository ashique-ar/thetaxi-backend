<?php

it('reconstructs frozen aging from retained schedule attribution revision and allocation evidence', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesFrozenCollectionAgingService.php'));
    $performance = file_get_contents(app_path('Services/Sales/SalesPerformanceService.php'));
    $close = file_get_contents(app_path('Services/Sales/SalesPeriodCloseService.php'));

    expect($service)
        ->toContain("public const BUCKETS = ['not_due', 'due_today', '1_30', '31_60', '61_90', '91_plus']")
        ->toContain("->where('schedule.created_at', '<=', \$cutoff)")
        ->toContain("->where('created_at', '<=', \$cutoff)->where('allocated_at', '<=', \$asOfBoundary)")
        ->toContain("->where('effective_at', '<=', \$asOfBoundary)")
        ->toContain("->orWhere('field_name', 'collection_sales_profile_id')")
        ->toContain('superseded_by_revision_id')
        ->toContain("'aging_source_checksum'")
        ->toContain("'lkr_state' => \$outstandingLkr === null ? 'missing' : 'complete'")
        ->and($performance)
        ->toContain('private readonly SalesFrozenCollectionAgingService $frozenAging')
        ->toContain("'aging_profile_lineage_missing_count'")
        ->toContain('...$aging')
        ->and($close)
        ->toContain("'aging_profile_or_revision_lineage_missing'")
        ->toContain("'aging_source_checksum'");
});

it('exposes only scoped frozen aging evidence and reconciles before pagination', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $portal = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-performance/sales-performance.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-performance/sales-performance.component.html'));

    expect($controller)
        ->toContain('public function trendAging(Request $request, string $snapshot): JsonResponse')
        ->toContain("Rule::in(SalesFrozenCollectionAgingService::BUCKETS)")
        ->toContain("\$this->dashboardProfileIds(")
        ->toContain("'reconciliation_status' => \$reconciliation")
        ->toContain("'schedules' => \$this->paginateCollection(")
        ->toContain('this source exposes no customer contact or payment evidence')
        ->and($routes)
        ->toContain("Route::get('snapshots/{snapshot}/aging', [SalesDashboardController::class, 'trendAging'])")
        ->toContain("->whereUuid('snapshot')->middleware('permission:sales.performance.view')")
        ->and($portal)
        ->toContain('performanceTrendAging(snapshotId')
        ->toContain('selectedTrendAgingSnapshotId')
        ->toContain('selectTrendAgingBucket')
        ->and($template)
        ->toContain('Authorized frozen collection-aging source')
        ->toContain('Governed LKR basis was unavailable at cutoff.')
        ->toContain("*hasPermission=\"'bookings.view'\"");
});
