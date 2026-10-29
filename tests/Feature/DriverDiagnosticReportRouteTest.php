<?php

use App\Http\Controllers\Api\Driver\Mobile\DiagnosticReportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('routes driver diagnostics to the mobile report and app-log handler', function () {
    $route = Route::getRoutes()->match(
        Request::create('/api/driver/diagnostic-reports', 'POST'),
    );

    expect($route->getActionName())->toBe(
        DiagnosticReportController::class.'@store',
    );
    expect($route->gatherMiddleware())->toContain('throttle:30,60');
});
