<?php

it('computes recruitment analytics read-only over the existing requisition/candidate/application/offer tables with no write path', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/RecruitmentController.php'));
    $method = substr($controller, strpos($controller, 'public function analytics'));
    $method = substr($method, 0, strpos($method, 'public function conversion'));

    expect($method)
        ->not->toContain('->insert(')
        ->not->toContain('->update(')
        ->not->toContain('$this->enabled()')
        ->toContain("->where('to_stage','hired')")
        ->toContain("'time_to_hire_days'=>")
        ->toContain("'source_effectiveness'=>")
        ->toContain("'funnel_by_stage'=>")
        ->toContain("'offer_acceptance_rate_percent'=>");
});

it('scopes recruitment analytics to the actor legal entity via the existing company() helper', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/RecruitmentController.php'));

    expect($controller)->toContain('$company=$this->company($r,$r->input(\'company_id\'));');
});

it('reuses the existing hr.recruitment.view permission for analytics rather than minting a new one', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)->toContain("Route::get('analytics',[RecruitmentController::class,'analytics'])->middleware('permission:hr.recruitment.view');");
});
