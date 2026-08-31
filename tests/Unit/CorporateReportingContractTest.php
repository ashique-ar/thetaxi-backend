<?php

uses(Tests\TestCase::class);

it('keeps corporate report rows pagination and statistics on one canonical response shape', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Corporate/CorporateReportController.php'));
    $service = file_get_contents(app_path('Services/CorporateBookingService.php'));

    expect($controller)
        ->toContain("'data' => \$bookings->items()")
        ->toContain("'meta' => [")
        ->toContain("'data' => \$stats")
        ->not->toContain("'data'   => ['bookings' => \$bookings]")
        ->not->toContain("'data'   => ['stats' => \$stats]")
        ->and($service)
        ->toContain("'total_bookings' => \$totalCount")
        ->toContain("'trip_count' => \$tripCount")
        ->toContain("'estimated_value' => \$estimatedValue")
        ->toContain("'finalized_value' => \$finalizedValue")
        ->toContain("'by_status' => \$byStatus")
        ->toContain("'by_department' => \$byDepartment");
});

it('downloads corporate csv exports instead of exposing private storage paths', function () {
    $reportController = file_get_contents(app_path('Http/Controllers/Api/Corporate/CorporateReportController.php'));
    $bookingController = file_get_contents(app_path('Http/Controllers/Api/Corporate/CorporateBookingController.php'));

    expect($reportController)
        ->toContain('public function exportCsv(CorporateReportFiltersRequest $request): StreamedResponse')
        ->toContain('return Storage::download(')
        ->not->toContain("['file_path' => \$filePath]")
        ->and($bookingController)
        ->toContain('public function export(CorporateReportFiltersRequest $request): StreamedResponse')
        ->toContain('return Storage::download(')
        ->not->toContain("['file_path' => \$filePath]");
});

it('keeps report filters aligned across rows summaries and exports', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Corporate/CorporateReportController.php'));
    $request = file_get_contents(app_path('Http/Requests/Corporate/CorporateReportFiltersRequest.php'));
    $frontend = file_get_contents(base_path('../portal-thetaxi/src/app/modules/corporate/components/reports/reports.component.ts'));

    expect($controller)
        ->toContain('CorporateReportFiltersRequest $request')
        ->toContain('$request->validated()')
        ->and($request)
        ->toContain("'department_id' => ['nullable', 'uuid']")
        ->toContain("'division_id' => ['nullable', 'uuid']")
        ->toContain("'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from']")
        ->toContain("->where('corporate_id', \$corporateId)")
        ->toContain("->whereHas('department'")
        ->and($frontend)
        ->toContain('private buildSummaryParams(): CorporateBookingFilters')
        ->toContain('params.department_id = this.departmentControl.value')
        ->toContain('params.division_id = this.divisionControl.value')
        ->toContain('params.status = this.statusControl.value');
});
