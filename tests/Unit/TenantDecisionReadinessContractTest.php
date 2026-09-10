<?php

it('keeps tenant readiness fail closed until every required decision is approved', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Admin/TenantDecisionController.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/admin/system/tenant-decisions/tenant-decisions.component.html'));

    expect($controller)->toContain("'activation_ready' => \$total > 0 && \$configured === \$total", "'blockers' => \$blockers", "if (\$x['pending_approval'])")
        ->and($template)->toContain('Activation readiness', 'Awaiting independent approval', 'remaining verification and activation gates')
        ->not->toContain("*ngIf=\"d.status === 'draft'\"\n                *hasPermission");
});
