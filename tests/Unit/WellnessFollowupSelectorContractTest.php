<?php

it('uses the referral-scoped bounded selector for wellness follow-up completion', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/EngagementController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $dialog = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-engagement/components/wellness-followups/wellness-followups.component.html'));

    expect($routes)->toContain("Route::get('wellness/referrals/{id}/followup-options', [EngagementController::class, 'wellnessFollowupOptions'])")
        ->and($controller)->toContain("where('referral_id', \$id)->where('status', 'scheduled')")
        ->and($controller)->toContain("where('company_id', \$actor->company_id)")
        ->and($controller)->toContain("'max' => 50")
        ->and($controller)->toContain("select(['id', 'due_at', 'timezone'])")
        ->and($dialog)->toContain("'/hr/engagement/wellness/referrals/' + id + '/followup-options'")
        ->and($dialog)->not->toContain('scheduled(d.followups)')
        ->and($controller)->toContain("where('f.id', \$id)->where('r.company_id', \$actor->company_id)->select('f.*', 'r.status as referral_status')");
});
