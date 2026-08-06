<?php

it('keeps bulk review atomic permission aware and connected to the portal selection', function (): void {
    $root = dirname(__DIR__, 2);
    $controller = file_get_contents($root . '/app/Http/Controllers/Api/Driver/DriverLogController.php');
    $routes = file_get_contents($root . '/routes/api.php');
    $service = file_get_contents($root . '/../portal-thetaxi/src/app/modules/logsheet/services/logsheet.service.ts');
    $dashboard = file_get_contents($root . '/../portal-thetaxi/src/app/modules/logsheet/components/logsheet-dashboard/logsheet-dashboard.component.ts');
    $template = file_get_contents($root . '/../portal-thetaxi/src/app/modules/logsheet/components/logsheet-dashboard/logsheet-dashboard.component.html');

    expect($routes)->toContain("logsheets/bulk-review', [DriverLogController::class, 'bulkReview']")
        ->and($controller)->toContain("'bulkReview'")
        ->and($controller)->toContain("'log_sheet_ids' => 'required|array|min:1|max:100'")
        ->and($controller)->toContain('DB::transaction(')
        ->and($controller)->toContain('->lockForUpdate()')
        ->and($controller)->toContain(')->resolve($request)')
        ->and($controller)->toContain("Only pending log sheets can be reviewed. Refresh the list and try again.")
        ->and($service)->toContain("log_sheet_ids: logSheetIds")
        ->and($dashboard)->toContain("hasPermission('driver-logs.edit')")
        ->and($dashboard)->toContain("bulkReview(action: 'approve' | 'reject')")
        ->and($template)->toContain("(click)=\"bulkReview('reject')\"")
        ->and($template)->toContain("(click)=\"bulkReview('approve')\"");
});

it('reports paid batta from the settlement owner instead of a hard coded zero', function (): void {
    $root = dirname(__DIR__, 2);
    $controller = file_get_contents($root . '/app/Http/Controllers/Api/Driver/DriverLogController.php');

    expect($controller)->toContain("DB::table('driver_hire_settlements')")
        ->and($controller)->toContain("->where('status', 'paid')")
        ->and($controller)->toContain("->sum('approved_batta_amount')")
        ->and($controller)->not->toContain("'total_allowances_paid' => 0");
});
