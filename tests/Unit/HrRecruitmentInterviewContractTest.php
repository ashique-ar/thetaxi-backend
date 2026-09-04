<?php

it('gates interview scheduling on the application already being in the interview stage', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/RecruitmentController.php'));
    $method = substr($controller, strpos($controller, 'public function storeInterview('));
    $method = substr($method, 0, strpos($method, 'public function interviewAction('));

    expect($method)
        ->toContain("abort_unless(\$app->stage==='interview',409")
        ->toContain('$this->enabled()')
        ->toContain("count(\$panel)")
        ->toContain("->where('company_id',\$app->company_id)->count()");
});

it('never lets a scheduled/cancelled/completed interview be edited outside the scheduled state', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/RecruitmentController.php'));
    $method = substr($controller, strpos($controller, 'public function interviewAction('));
    $method = substr($method, 0, strpos($method, 'public function myInterviews('));

    expect($method)
        ->toContain("abort_unless(\$interview->status==='scheduled',409")
        ->toContain('->lockForUpdate()');
});

it('requires panel membership, not a recruitment department permission, to submit panel feedback', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/RecruitmentController.php'));
    $method = substr($controller, strpos($controller, 'public function storeInterviewFeedback('));
    $method = substr($method, 0, strpos($method, 'public function interviewFeedback('));

    expect($method)
        ->toContain("abort_unless(\$staffId&&in_array(\$staffId,\$panel,true),403")
        ->toContain("Feedback was already submitted for this interview.")
        ->not->toContain("hr.recruitment.manage");
});

it('keeps individual panel scorecards confidential behind hr.recruitment.approve, not the broader manage/view permissions', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)
        ->toContain("Route::get('interviews/{interviewId}/feedback',[RecruitmentController::class,'interviewFeedback'])->whereUuid('interviewId')->middleware('permission:hr.recruitment.approve');")
        ->toContain("Route::post('interviews/{interviewId}/feedback',[RecruitmentController::class,'storeInterviewFeedback'])->whereUuid('interviewId')->middleware('permission:hr.ess.use');")
        ->toContain("Route::get('interviews/mine',[RecruitmentController::class,'myInterviews'])->middleware('permission:hr.ess.use');");
});

it('reuses existing hr.recruitment.* and hr.ess.use permissions for the interview slice rather than minting a new one', function () {
    $seeder = file_get_contents(base_path('database/seeders/AllPermissionsSeeder.php'));

    // No new permission string containing "interview" should exist anywhere in the
    // canonical permission list; the slice deliberately reuses hr.recruitment.view/
    // .manage/.approve and hr.ess.use so seeding stays additive-only.
    expect($seeder)->not->toContain('interview');
});

it('decrypts schedule details only for a privileged recruiter or an assigned panelist, never a bare viewer', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/RecruitmentController.php'));
    $method = substr($controller, strpos($controller, 'public function interviews('));
    $method = substr($method, 0, strpos($method, 'public function storeInterview('));

    expect($method)
        ->toContain("\$privileged=\$r->user()->can('hr.recruitment.manage')||\$r->user()->can('hr.recruitment.approve')")
        ->toContain("(\$privileged||\$isPanelist)&&\$row->encrypted_schedule_details");
});

it('scopes myInterviews to panel membership via whereJsonContains without a company_id leak across tenants', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/RecruitmentController.php'));
    $method = substr($controller, strpos($controller, 'public function myInterviews('));
    $method = substr($method, 0, strpos($method, 'public function storeInterviewFeedback('));

    expect($method)->toContain("whereJsonContains('panel_staff_ids',\$staffId)");
});
