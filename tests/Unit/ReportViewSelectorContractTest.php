<?php

it('uses a bounded authorized report view selector when creating schedules', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/HrReportingController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $dialog = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-engagement/components/delivery-administration/report-schedule-dialog.component.ts'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-engagement/components/delivery-administration/delivery-administration.component.ts'));

    expect($routes)->toContain("Route::get('report-view-options', [HrReportingController::class, 'viewOptions'])")
        ->and($controller)->toContain("whereIn('report_kind',\$this->access->allowedKinds(\$r->user()))")
        ->toContain("where('status','active')")
        ->toContain("orWhere('visibility','company')")
        ->toContain("where('status','active')->where(fn(\$q)=>\$q->where('owner_user_id',\$r->user()->id)->orWhere('visibility','company'))")
        ->and($controller)->toContain('function storeSchedule')
        ->toContain('Saved view is not available for scheduling.')
        ->and($dialog)->toContain('endpoint="/hr/analytics/report-view-options"')
        ->toContain("metadata?.report_kind")
        ->not->toContain('data.views')
        ->and($component)->not->toContain('per_page: 100')
        ->not->toContain('this.ensureViews');
});
